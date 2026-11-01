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
    repair_candidates::{self, ScopeRow},
    retention,
    store::{Store, now},
};
use chrono::{DateTime, Duration, SecondsFormat, Timelike, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// PHP's `TaskRepairPolicy::redispatchAfterSeconds()` default: a ready task
/// still unclaimed this long after it was made available is overdue.
pub(super) const REDISPATCH_AFTER_SECONDS: i64 = 3;

/// Laravel binds a bare timestamp as `Y-m-d H:i:s`.
pub(super) fn whole_second(at: DateTime<Utc>) -> DateTime<Utc> {
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

/// A dashboard query without and with `summaryQuery`'s workflow type filter.
/// The filtered form adds `$column IN (...)` over the type list, bound once as
/// a JSON array at `$n`, the last position. MySQL compares through the
/// column's collation, as PHP's `whereIn` does.
struct TypedSql {
    all: &'static str,
    sqlite: &'static str,
    postgres: &'static str,
    mysql: &'static str,
}

impl TypedSql {
    fn pick<DB: sqlx::Database>(&self, typed: bool) -> &'static str {
        match (typed, DB::NAME) {
            (false, _) => self.all,
            (true, "PostgreSQL") => self.postgres,
            (true, "MySQL") => self.mysql,
            (true, _) => self.sqlite,
        }
    }
}

macro_rules! by_type {
    // A head built by another macro comes in brackets: `[summaries!("...")]`.
    ([$($head:tt)+], $column:literal, $n:literal, $tail:literal) => {
        TypedSql {
            all: concat!($($head)+, $tail),
            sqlite: concat!($($head)+, " AND ", $column, " IN (SELECT value FROM json_each(", $n, "))", $tail),
            postgres: concat!($($head)+, " AND ", $column, " IN (SELECT jsonb_array_elements_text(CAST(", $n, " AS jsonb)))", $tail),
            mysql: concat!($($head)+, " AND ", $column, " IN (SELECT jt.t FROM JSON_TABLE(", $n,
                ",'$[*]' COLUMNS(t VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '$')) jt)", $tail),
        }
    };
    ($head:literal, $column:literal, $n:literal, $tail:literal) => {
        by_type!([$head], $column, $n, $tail)
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

/// The summary columns `WorkflowRunSummary::toArray()` reports, with flags
/// read as numbers on every backend.
macro_rules! summary_columns {
    () => {
        "SELECT id,workflow_instance_id,run_number,CASE WHEN is_current_run THEN 1 ELSE 0 END AS is_current_run,engine_source,projection_schema_version,class,workflow_type,namespace,compatibility,declared_entry_mode,declared_contract_source,business_key,visibility_labels,status,status_bucket,closed_reason,connection,queue,started_at,sort_timestamp,sort_key,closed_at,archived_at,archive_command_id,archive_reason,duration_ms,wait_kind,wait_reason,wait_started_at,wait_deadline_at,open_wait_id,resume_source_kind,resume_source_id,next_task_at,liveness_state,liveness_reason,repair_blocked_reason,CASE WHEN repair_attention THEN 1 ELSE 0 END AS repair_attention,CASE WHEN task_problem THEN 1 ELSE 0 END AS task_problem,next_task_id,next_task_type,next_task_status,next_task_lease_expires_at,exception_count,history_event_count,history_size_bytes,CASE WHEN continue_as_new_recommended THEN 1 ELSE 0 END AS continue_as_new_recommended,created_at,updated_at,history_fan_out,history_budget_pressure FROM workflow_run_summaries"
    };
}

const SUMMARY_TEXT: &[&str] = &["id", "workflow_instance_id", "engine_source", "class", "workflow_type", "namespace",
    "compatibility", "declared_entry_mode", "declared_contract_source", "business_key", "status", "status_bucket",
    "closed_reason", "connection", "queue", "sort_key", "archive_command_id", "archive_reason", "wait_kind", "wait_reason",
    "open_wait_id", "resume_source_kind", "resume_source_id", "liveness_state", "liveness_reason", "repair_blocked_reason",
    "next_task_id", "next_task_type", "next_task_status", "history_budget_pressure"];
const SUMMARY_NUMBERS: &[&str] = &["run_number", "projection_schema_version", "duration_ms", "exception_count",
    "history_event_count", "history_size_bytes", "history_fan_out"];
const SUMMARY_FLAGS: &[&str] = &["is_current_run", "repair_attention", "task_problem", "continue_as_new_recommended"];
const SUMMARY_TIMES: &[&str] = &["started_at", "sort_timestamp", "closed_at", "archived_at", "wait_started_at",
    "wait_deadline_at", "next_task_at", "next_task_lease_expires_at", "created_at", "updated_at"];

/// `RepairBlockedReason::metadata`.
fn repair_blocked(code: Option<&str>) -> Value {
    let (label, description, tone, visible) = match code {
        Some("unsupported_history") => ("Replay Blocked", "Repair is blocked because only unsupported diagnostic history remains.", "dark", true),
        Some("waiting_for_compatible_worker") => ("Compat Blocked", "Repair is blocked because the task is waiting for a compatible worker.", "warning", true),
        Some("selected_run_not_current") => ("Historical Run", "Repair is blocked because the selected run is no longer current.", "secondary", false),
        Some("run_closed") => ("Run Closed", "Repair is blocked because the selected run is already closed.", "secondary", false),
        Some("repair_not_needed") => ("Repair Not Needed", "Repair is blocked because the run already has a healthy durable resume path.", "secondary", false),
        _ => return Value::Null,
    };
    json!({"code": code, "label": label, "description": description, "tone": tone, "badge_visible": visible})
}

/// PHP's `$flowsPastHour / 60`: an integer when it divides evenly.
fn per_minute(flows: i64) -> Value {
    if flows % 60 == 0 { json!(flows / 60) } else { json!(flows as f64 / 60.0) }
}

/// Carbon's `toIso8601String()`.
fn iso8601(at: DateTime<Utc>) -> String {
    at.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()
}

fn start_of_hour(at: DateTime<Utc>) -> DateTime<Utc> {
    at.with_minute(0).and_then(|at| at.with_second(0)).and_then(|at| at.with_nanosecond(0)).unwrap_or(at)
}

/// `OperatorMetrics::snapshot(..., includeHistoryAudits: false)`: the history
/// audits are marked as not requested and their counts are null.
fn without_history_audits(metrics: &mut Value) {
    metrics["history_audit_evaluation"] = json!("not_requested");
    if let Some(contracts) = metrics["command_contracts"].as_object_mut() {
        for value in contracts.values_mut() {
            *value = Value::Null;
        }
    }
    for field in ["runs_with_waits", "projected_runs_with_waits", "missing_runs_with_waits", "stale_projected_runs", "needs_rebuild"] {
        if metrics["projections"]["run_waits"].get(field).is_some() {
            metrics["projections"]["run_waits"][field] = Value::Null;
        }
    }
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
            "backend": backend_capabilities(now, DB::PHP_CONNECTION, &config.structural, self.memo_size_limit),
            "structural_limits": structural_limits(DB::PHP_CONNECTION, &config.structural, self.memo_size_limit),
            "update_wait": {"completion_timeout_seconds": 10, "poll_interval_milliseconds": 50},
            "repair_policy": repair_policy(),
            "matching_role": matching_role(config),
            "sticky_execution": sticky_execution(),
        }))
    }

    /// `StructuralLimits::snapshot` as `/api/cluster/info` reports it: the
    /// configured ceilings operator metrics reports. Native reads no
    /// `DW_V2_LIMIT_EXTERNAL_PAYLOAD_TRANSACTION_BYTES`, so that member is
    /// left out and named as unqualified.
    pub(super) async fn cluster_structural_limits(&self, config: &MetricsConfig) -> Result<Value> {
        Ok(structural_limits(DB::PHP_CONNECTION, &config.structural, self.memo_size_limit)["configured"].clone())
    }

    /// `GET /api/system/operator-metrics`: the snapshot with the sections the
    /// server adds.
    pub(super) async fn operator_metrics(&self, config: &MetricsConfig) -> Result<Value> {
        let mut metrics = self.operator_metrics_snapshot(config).await?;
        metrics["worker_sessions"] = self.worker_session_metrics().await?;
        metrics["capacity_evidence"] =
            json!({"available": false, "reason": "native_capacity_evidence_unavailable"});
        metrics["runtime_external_payload_cleanup"] =
            self.external_payload_cleanup_metrics(config).await?;
        Ok(json!({"namespace": self.namespace.as_ref(), "operator_metrics": metrics}))
    }

    /// `SystemController::dashboardResponse`. The bounded dashboard skips
    /// PHP's history audits. `types` is `workflowTypeDashboardSummary`'s
    /// workflow type filter, always on the bounded dashboard. It narrows the
    /// run figures; the operator metrics keep the whole namespace.
    pub(super) async fn operator_dashboard(
        &self,
        config: &MetricsConfig,
        bounded: bool,
        types: Option<&[String]>,
    ) -> Result<Value> {
        let now = now();
        let mut metrics = self.operator_metrics_snapshot(config).await?;
        if bounded {
            without_history_audits(&mut metrics);
            metrics["capacity_evidence"] =
                json!({"available": false, "reason": "native_capacity_evidence_unavailable"});
        }
        metrics["worker_sessions"] = self.worker_session_metrics().await?;
        metrics["runtime_external_payload_cleanup"] = self.external_payload_cleanup_metrics(config).await?;
        let filter = types.map(|types| Value::from(types.to_vec()).to_string());
        let filter = filter.as_deref();
        let hour = now - Duration::hours(1);
        let day = now - Duration::days(1);
        let week = now - Duration::weeks(1);
        const FLOWS: TypedSql = by_type!("SELECT COUNT(*) FROM workflow_run_summaries WHERE namespace=$1", "workflow_type", "$2", "");
        const FLOWS_PAST_HOUR: TypedSql = by_type!([summaries!("(sort_timestamp>=$2 OR (sort_timestamp IS NULL AND created_at>=$3))")], "workflow_type", "$4", "");
        // A failure belongs to one run, so counting joined rows equals
        // PHP's `whereHas('run', ...)`.
        const EXCEPTIONS: TypedSql = by_type!("SELECT COUNT(*) FROM workflow_failures f JOIN workflow_runs r ON r.id=f.workflow_run_id WHERE r.namespace=$1 AND f.created_at>=$2", "r.workflow_type", "$3", "");
        const FAILED_WEEK: TypedSql = by_type!([summaries!("status='failed' AND updated_at>=$2")], "workflow_type", "$3", "");
        const MAX_WAIT: TypedSql = by_type!([concat!(summary_columns!(), " WHERE namespace=$1 AND status_bucket='running' AND wait_started_at IS NOT NULL")], "workflow_type", "$2", " ORDER BY wait_started_at,sort_timestamp,created_at,id LIMIT 1");
        const MAX_DURATION: TypedSql = by_type!([concat!(summary_columns!(), " WHERE namespace=$1 AND duration_ms IS NOT NULL")], "workflow_type", "$2", " ORDER BY duration_ms DESC,sort_timestamp DESC,created_at DESC,id DESC LIMIT 1");
        const MAX_EXCEPTIONS: TypedSql = by_type!([concat!(summary_columns!(), " WHERE namespace=$1 AND exception_count>0")], "workflow_type", "$2", " ORDER BY exception_count DESC,sort_timestamp DESC,created_at DESC,id DESC LIMIT 1");
        const CURRENT: TypedSql = by_type!("SELECT status_bucket AS bucket,COUNT(*) AS n FROM workflow_run_summaries WHERE namespace=$1 AND status_bucket<>'completed'", "workflow_type", "$2", " GROUP BY status_bucket");
        let flows_past_hour = self.typed_tally(&FLOWS_PAST_HOUR, &[hour, hour], filter).await?;
        let mut dashboard = json!({
            "flows": self.typed_tally(&FLOWS, &[], filter).await?,
            "flows_per_minute": per_minute(flows_past_hour),
            "flows_past_hour": flows_past_hour,
            "exceptions_past_hour": self.typed_tally(&EXCEPTIONS, &[hour], filter).await?,
            "failed_flows_past_week": self.typed_tally(&FAILED_WEEK, &[now - Duration::days(7)], filter).await?,
            "max_wait_time_workflow": self.summary_row(&MAX_WAIT, filter).await?,
            "max_duration_workflow": self.summary_row(&MAX_DURATION, filter).await?,
            "max_exceptions_workflow": self.summary_row(&MAX_EXCEPTIONS, filter).await?,
            "fleet_overview": {
                "current": self.bucket_counts(&CURRENT, None, &["running", "failed"], filter).await?,
                "trends": {
                    "hour": self.closed_counts(hour, filter).await?,
                    "day": self.closed_counts(day, filter).await?,
                    "week": self.closed_counts(week, filter).await?,
                },
            },
            "workflow_type_health": self.workflow_type_health(week, filter).await?,
            "needs_attention": self.needs_attention(now, filter).await?,
            "fleet_trends_series": self.fleet_trends(now, filter).await?,
            "workflow_scope": {"namespace": self.namespace.as_ref(), "workflow_types": types,
                "all_workflow_types": types.is_none()},
            "time_windows": {"generated_at": iso8601(now), "total_runs": "all_retained_runs", "current_status": "current",
                "recent_hour_from": iso8601(hour), "recent_day_from": iso8601(day), "recent_week_from": iso8601(week),
                "trends_resolution": "hour"},
            "operator_metrics_scope": {"namespace": self.namespace.as_ref(), "workflow_types": null},
        });
        dashboard["operator_metrics"] = metrics;
        Ok(json!({"namespace": self.namespace.as_ref(), "dashboard": dashboard}))
    }

    /// A namespace count, with any further bound timestamps and then the
    /// workflow type filter.
    async fn typed_tally(&self, sql: &TypedSql, times: &[DateTime<Utc>], types: Option<&str>) -> Result<i64> {
        let mut query = Self::scalar(sql.pick::<DB>(types.is_some())).bind(self.namespace.as_ref());
        for at in times {
            query = query.bind(DB::bind_time(*at));
        }
        if let Some(types) = types {
            query = query.bind(types);
        }
        Ok(query.fetch_one(&self.pool).await?)
    }

    /// One summary as Eloquent's `WorkflowRunSummary::toArray()`, or null.
    async fn summary_row(&self, sql: &TypedSql, types: Option<&str>) -> Result<Value> {
        let mut query = Self::query(sql.pick::<DB>(types.is_some())).bind(self.namespace.as_ref());
        if let Some(types) = types {
            query = query.bind(types);
        }
        let row = query.fetch_optional(&self.pool).await?;
        row.map(|row| Self::summary_array(&row)).transpose().map(|row| row.unwrap_or(Value::Null))
    }

    /// The summary's columns with the model's casts, then its appends.
    fn summary_array(row: &DB::Row) -> Result<Value> {
        let mut fields = serde_json::Map::new();
        for column in SUMMARY_TEXT {
            fields.insert((*column).to_owned(), json!(DB::optional_string(row, column)?));
        }
        for column in SUMMARY_NUMBERS {
            fields.insert((*column).to_owned(), json!(DB::optional_number(row, column)?));
        }
        for column in SUMMARY_FLAGS {
            fields.insert((*column).to_owned(), json!(DB::optional_number(row, column)?.map(|flag| flag != 0)));
        }
        for column in SUMMARY_TIMES {
            fields.insert((*column).to_owned(), time(DB::optional_instant(row, column)?));
        }
        fields.insert("visibility_labels".into(), DB::optional_document_row(row, "visibility_labels")?.unwrap_or(Value::Null));
        let status = DB::optional_string(row, "status")?.unwrap_or_default();
        let text = |field: &str| fields.get(field).cloned().unwrap_or(Value::Null);
        let appended = json!({"instance_id": text("workflow_instance_id"), "selected_run_id": text("id"), "run_id": text("id"),
            "exceptions_count": DB::optional_number(row, "exception_count")?.unwrap_or_default(),
            "is_terminal": matches!(status.as_str(), "completed" | "failed" | "cancelled" | "terminated"),
            "repair_blocked": repair_blocked(DB::optional_string(row, "repair_blocked_reason")?.as_deref()),
            "task_problem_badge": super::run_diagnostics::task_problem_badge(
                fields["task_problem"] == true,
                fields["liveness_state"].as_str(),
                fields["wait_kind"].as_str(),
            )});
        if let Value::Object(appended) = appended {
            fields.extend(appended);
        }
        Ok(Value::Object(fields))
    }

    /// `pluck('count', 'status_bucket')` over `buckets`, zero when absent.
    async fn bucket_counts(&self, sql: &TypedSql, since: Option<DateTime<Utc>>, buckets: &[&str], types: Option<&str>) -> Result<Value> {
        let mut query = Self::query(sql.pick::<DB>(types.is_some())).bind(self.namespace.as_ref());
        if let Some(since) = since {
            query = query.bind(DB::bind_time(since));
        }
        if let Some(types) = types {
            query = query.bind(types);
        }
        let mut counts = serde_json::Map::new();
        for bucket in buckets {
            counts.insert((*bucket).to_owned(), json!(0));
        }
        for row in query.fetch_all(&self.pool).await? {
            let bucket = DB::string(&row, "bucket")?;
            if counts.contains_key(&bucket) {
                counts.insert(bucket, json!(DB::number(&row, "n")?));
            }
        }
        Ok(Value::Object(counts))
    }

    async fn closed_counts(&self, since: DateTime<Utc>, types: Option<&str>) -> Result<Value> {
        const CLOSED: TypedSql = by_type!("SELECT status_bucket AS bucket,COUNT(*) AS n FROM workflow_run_summaries WHERE namespace=$1 AND closed_at>=$2 AND status_bucket IN ('completed','failed')",
            "workflow_type", "$3", " GROUP BY status_bucket");
        self.bucket_counts(&CLOSED, Some(since), &["completed", "failed"], types).await
    }

    /// `workflowTypeHealth`: the week's ten busiest workflow types. Each
    /// listed type passed the filter, so its own figures need no other.
    async fn workflow_type_health(&self, week: DateTime<Utc>, filter: Option<&str>) -> Result<Value> {
        const BUSIEST: TypedSql = by_type!("SELECT workflow_type,COUNT(*) AS total_runs FROM workflow_run_summaries WHERE namespace=$1 AND created_at>=$2",
            "workflow_type", "$3", " GROUP BY workflow_type ORDER BY total_runs DESC LIMIT 10");
        let mut busiest = Self::query(BUSIEST.pick::<DB>(filter.is_some())).bind(self.namespace.as_ref()).bind(DB::bind_time(week));
        if let Some(filter) = filter {
            busiest = busiest.bind(filter);
        }
        let types = busiest.fetch_all(&self.pool).await?;
        let mut health = Vec::with_capacity(types.len());
        for row in types {
            let workflow_type = DB::string(&row, "workflow_type")?;
            let total = DB::number(&row, "total_runs")?;
            let mut statuses = std::collections::HashMap::new();
            for row in Self::query("SELECT status,COUNT(*) AS n FROM workflow_run_summaries WHERE namespace=$1 AND workflow_type=$2 AND created_at>=$3 GROUP BY status")
                .bind(self.namespace.as_ref()).bind(workflow_type.as_str()).bind(DB::bind_time(week)).fetch_all(&self.pool).await?
            {
                statuses.insert(DB::string(&row, "status")?, DB::number(&row, "n")?);
            }
            let count = |status: &str| statuses.get(status).copied().unwrap_or(0);
            let (completed, failed, cancelled, terminated) = (count("completed"), count("failed"), count("cancelled"), count("terminated"));
            let terminal = completed + failed + cancelled + terminated;
            let pass_rate = if terminal > 0 { completed as f64 / terminal as f64 * 100.0 } else { 0.0 };
            let durations = Self::scalar("SELECT COUNT(*) FROM workflow_run_summaries WHERE namespace=$1 AND workflow_type=$2 AND status='completed' AND created_at>=$3 AND duration_ms IS NOT NULL")
                .bind(self.namespace.as_ref()).bind(workflow_type.as_str()).bind(DB::bind_time(week)).fetch_one(&self.pool).await?;
            // The upper-middle duration, as PHP's offset query picks it.
            let median = if durations > 0 {
                Self::query("SELECT duration_ms FROM workflow_run_summaries WHERE namespace=$1 AND workflow_type=$2 AND status='completed' AND created_at>=$3 AND duration_ms IS NOT NULL ORDER BY duration_ms LIMIT 1 OFFSET $4")
                    .bind(self.namespace.as_ref()).bind(workflow_type.as_str()).bind(DB::bind_time(week)).bind(durations / 2)
                    .fetch_optional(&self.pool).await?
                    .map(|row| DB::optional_number(&row, "duration_ms")).transpose()?.flatten()
            } else {
                None
            };
            health.push(json!({"workflow_type": workflow_type, "workflow_class": workflow_type, "total_runs": total,
                "pass_rate": (pass_rate * 10.0).round() / 10.0, "median_duration_ms": median,
                "error_count": failed + cancelled + terminated,
                "status_breakdown": {"completed": completed, "failed": failed, "cancelled": cancelled, "terminated": terminated}}));
        }
        Ok(json!(health))
    }

    /// `needsAttention`: PHP's alert rules over workers and run summaries.
    async fn needs_attention(&self, now: DateTime<Utc>, types: Option<&str>) -> Result<Value> {
        let hour = now - Duration::hours(1);
        let mut alerts = Vec::new();
        let stuck = self.tally("SELECT COUNT(*) FROM workflow_worker_compatibility_heartbeats WHERE namespace=$1 AND recorded_at<$2 AND recorded_at>$3",
            &[now - Duration::minutes(5), hour]).await?;
        if stuck > 0 {
            alerts.push(json!({"type": "stuck_workers", "scope": "operator_workers", "severity": "warning",
                "message": format!("{stuck} worker(s) have not sent heartbeat in 5+ minutes"), "count": stuck,
                "action": "Check worker health and restart if needed"}));
        }
        let long_running = self.typed_tally(&by_type!([summaries!("status_bucket='running' AND started_at<$2 AND wait_started_at IS NULL")], "workflow_type", "$3", ""), &[hour], types).await?;
        if long_running > 0 {
            alerts.push(json!({"type": "long_running", "severity": "info",
                "message": format!("{long_running} workflow(s) running longer than 1 hour"), "count": long_running,
                "action": "Review workflow duration expectations"}));
        }
        let storms = self.typed_tally(&by_type!([summaries!("status_bucket='running' AND updated_at>=$2 AND exception_count>=10")], "workflow_type", "$3", ""), &[hour], types).await?;
        if storms > 0 {
            alerts.push(json!({"type": "retry_storm", "severity": "error",
                "message": format!("{storms} workflow(s) with 10+ exceptions in last hour"), "count": storms,
                "action": "Investigate activity failures and fix root cause"}));
        }
        let failed = self.typed_tally(&by_type!([summaries!("status='failed' AND closed_at>=$2")], "workflow_type", "$3", ""), &[hour], types).await?;
        let completed = self.typed_tally(&by_type!([summaries!("status='completed' AND closed_at>=$2")], "workflow_type", "$3", ""), &[hour], types).await?;
        let total = failed + completed;
        if total >= 10 && failed as f64 / total as f64 * 100.0 > 20.0 {
            alerts.push(json!({"type": "high_failure_rate", "severity": "error",
                "message": format!("{:.0}% failure rate in last hour ({failed}/{total})", failed as f64 / total as f64 * 100.0),
                "count": failed, "action": "Review failed workflows for common patterns"}));
        }
        let overdue = now - Duration::minutes(30);
        let long_waits = self.typed_tally(&by_type!([summaries!("status_bucket='running' AND wait_started_at IS NOT NULL AND (task_problem=TRUE OR repair_attention=TRUE OR (wait_kind IN ('activity','child','signal','condition','update') AND wait_deadline_at<=$2) OR (next_task_status='ready' AND next_task_at<$3) OR (wait_kind='timer' AND wait_deadline_at<$4 AND (next_task_status IS NULL OR next_task_status<>'leased')))")],
            "workflow_type", "$5", ""), &[now, overdue, overdue], types).await?;
        if long_waits > 0 {
            alerts.push(json!({"type": "long_waits", "severity": "warning",
                "message": format!("{long_waits} workflow(s) with blocked or overdue waits"), "count": long_waits,
                "action": "Inspect wait deadlines and task dispatch before recovering work"}));
        }
        let critical = alerts.iter().any(|alert| alert["severity"] == "error");
        Ok(json!({"total_alerts": alerts.len(), "has_critical": critical, "alerts": alerts}))
    }

    /// `fleetTrendsSeries`: completed and failed runs per hour for a week.
    async fn fleet_trends(&self, now: DateTime<Utc>, types: Option<&str>) -> Result<Value> {
        const POSTGRES: TypedSql = by_type!("SELECT TO_CHAR(closed_at, 'YYYY-MM-DD HH24:00:00') AS hour_bucket,status_bucket,COUNT(*) AS run_count FROM workflow_run_summaries WHERE namespace=$1 AND closed_at IS NOT NULL AND closed_at>=$2 AND closed_at<$3 AND status_bucket IN ('completed','failed')",
            "workflow_type", "$4", " GROUP BY TO_CHAR(closed_at, 'YYYY-MM-DD HH24:00:00'),status_bucket");
        const MYSQL: TypedSql = by_type!("SELECT DATE_FORMAT(closed_at, '%Y-%m-%d %H:00:00') AS hour_bucket,status_bucket,COUNT(*) AS run_count FROM workflow_run_summaries WHERE namespace=$1 AND closed_at IS NOT NULL AND closed_at>=$2 AND closed_at<$3 AND status_bucket IN ('completed','failed')",
            "workflow_type", "$4", " GROUP BY DATE_FORMAT(closed_at, '%Y-%m-%d %H:00:00'),status_bucket");
        const SQLITE: TypedSql = by_type!("SELECT STRFTIME('%Y-%m-%d %H:00:00', closed_at) AS hour_bucket,status_bucket,COUNT(*) AS run_count FROM workflow_run_summaries WHERE namespace=$1 AND closed_at IS NOT NULL AND closed_at>=$2 AND closed_at<$3 AND status_bucket IN ('completed','failed')",
            "workflow_type", "$4", " GROUP BY STRFTIME('%Y-%m-%d %H:00:00', closed_at),status_bucket");
        let week = now - Duration::weeks(1);
        let start = start_of_hour(week);
        let end = start_of_hour(now);
        let sql = match DB::NAME {
            "PostgreSQL" => &POSTGRES,
            "MySQL" => &MYSQL,
            _ => &SQLITE,
        };
        let mut query = Self::query(sql.pick::<DB>(types.is_some())).bind(self.namespace.as_ref()).bind(DB::bind_time(week))
            .bind(DB::bind_time(end + Duration::hours(1)));
        if let Some(types) = types {
            query = query.bind(types);
        }
        let rows = query.fetch_all(&self.pool).await?;
        let mut counts = std::collections::HashMap::new();
        for row in rows {
            counts.insert((DB::string(&row, "hour_bucket")?, DB::string(&row, "status_bucket")?), DB::number(&row, "run_count")?);
        }
        let (mut timestamps, mut completed, mut failed) = (Vec::new(), Vec::new(), Vec::new());
        let mut hour = start;
        while hour <= end {
            let key = hour.format("%Y-%m-%d %H:00:00").to_string();
            timestamps.push(hour.timestamp() * 1000);
            completed.push(counts.get(&(key.clone(), "completed".to_owned())).copied().unwrap_or(0));
            failed.push(counts.get(&(key, "failed".to_owned())).copied().unwrap_or(0));
            hour += Duration::hours(1);
        }
        Ok(json!({"timestamps": timestamps, "completed": completed, "failed": failed}))
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
    pub(super) async fn external_payload_cleanup_metrics(&self, config: &MetricsConfig) -> Result<Value> {
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

    /// `GET /api/system/repair`: native's repair policy, and the candidates
    /// published PHP's repair pass would select across every namespace.
    pub(super) async fn repair_status(&self) -> Result<Value> {
        let at = now();
        let unfiltered = super::repair_pass::PassRequest::default();
        let tasks = self.repairable_task_scopes(&unfiltered, at).await?;
        let runs = self.missing_task_scopes(&unfiltered).await?;
        let oldest_run = match Self::query(repair_candidates::OLDEST_RUN).fetch_optional(&self.pool).await? {
            Some(row) => DB::optional_instant(&row, "wait_started_at")?
                .or(DB::optional_instant(&row, "started_at")?)
                .or(DB::optional_instant(&row, "created_at")?),
            None => None,
        };
        Ok(json!({"policy": repair_policy(), "candidates": repair_candidates::snapshot(&tasks, &runs, oldest_run, at)}))
    }

    /// `HistoryRetentionEnforcer::retentionPolicy` for this namespace.
    async fn retention_policy(&self) -> Result<retention::Policy> {
        let row = Self::query("SELECT retention_mode,retention_days FROM workflow_namespaces WHERE name=$1")
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?;
        let (mode, days) = match &row {
            Some(row) => (DB::optional_string(row, "retention_mode")?, DB::optional_number(row, "retention_days")?),
            None => (None, None),
        };
        Ok(retention::Policy::new(mode.as_deref(), days, now()))
    }

    /// `expiredRunIds`: closed, unarchived completed or failed runs past the
    /// cutoff, oldest first.
    async fn expired_run_ids(&self, policy: &retention::Policy, limit: i64) -> Result<Vec<String>> {
        let Some(cutoff) = policy.cutoff else {
            return Ok(Vec::new());
        };
        Self::query("SELECT id FROM workflow_run_summaries WHERE namespace=$1 AND status_bucket IN ('completed','failed') AND closed_at IS NOT NULL AND archived_at IS NULL AND closed_at<$2 ORDER BY closed_at,id LIMIT $3")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(cutoff))
            .bind(limit)
            .fetch_all(&self.pool)
            .await?
            .iter()
            .map(|row| DB::string(row, "id"))
            .collect()
    }

    /// `GET /api/system/retention`.
    pub(super) async fn retention_status(&self, limit: i64) -> Result<Value> {
        let policy = self.retention_policy().await?;
        let expired = self.expired_run_ids(&policy, limit).await?;
        let mut fields = policy.fields(self.namespace.as_ref());
        fields.insert("expired_run_count".into(), json!(expired.len()));
        fields.insert("scan_pressure".into(), json!(expired.len() as i64 >= limit));
        fields.insert("expired_run_ids".into(), json!(expired));
        fields.insert("scan_limit".into(), json!(limit));
        Ok(Value::Object(fields))
    }

    /// `POST /api/system/retention/pass`: the report, and whether any run failed.
    pub(super) async fn retention_pass(&self, limit: i64, run_ids: Vec<String>) -> Result<(Value, bool)> {
        // A general pass also reclaims closed workflow streams past their own
        // retention (Server #774), at most `limit` streams and `limit` * 100
        // items. The report is the run pass's; a stream failure leaves its
        // items for the next pass and does not stop the run pass.
        if run_ids.is_empty()
            && let Err(error) = self.prune_expired_streams(limit, limit * 100).await
        {
            eprintln!("workflow_stream_retention_failed: {error}");
        }
        let policy = self.retention_policy().await?;
        let report = self.prune_runs(&policy, limit, run_ids).await?;
        let failed = report["failed"].as_i64().is_some_and(|failed| failed > 0);
        let mut fields = policy.fields(self.namespace.as_ref());
        fields.extend(report);
        fields.insert("external_payload_cleanup".into(), self.payload_deletion_status(self.namespace.as_ref()).await?);
        Ok((Value::Object(fields), failed))
    }

    /// `ActivityTimeoutScanner::expiredExecutionIds`: open activity
    /// executions past a deadline. Like PHP's scan, it spans every namespace.
    async fn expired_activity_ids(&self, limit: i64) -> Result<Vec<String>> {
        let at = now();
        Self::query("SELECT id FROM activity_executions WHERE status IN ('pending','running') AND ((status='pending' AND schedule_deadline_at IS NOT NULL AND schedule_deadline_at<=$1) OR (status='running' AND close_deadline_at IS NOT NULL AND close_deadline_at<=$2) OR (schedule_to_close_deadline_at IS NOT NULL AND schedule_to_close_deadline_at<=$3) OR (status='running' AND heartbeat_deadline_at IS NOT NULL AND heartbeat_deadline_at<=$4)) ORDER BY id LIMIT $5")
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .bind(limit)
            .fetch_all(&self.pool)
            .await?
            .iter()
            .map(|row| DB::string(row, "id"))
            .collect()
    }

    /// `GET /api/system/activity-timeouts`.
    pub(super) async fn activity_timeout_status(&self, limit: i64) -> Result<Value> {
        let expired = self.expired_activity_ids(limit).await?;
        Ok(json!({"expired_count": expired.len(), "scan_pressure": expired.len() as i64 >= limit,
            "expired_execution_ids": expired, "scan_limit": limit}))
    }

    /// `POST /api/system/activity-timeouts/pass`: the given executions, or
    /// the expired ones. Returns the report and whether any failed.
    pub(super) async fn activity_timeout_pass(&self, limit: i64, ids: Vec<String>) -> Result<(Value, bool)> {
        let ids = if ids.is_empty() { self.expired_activity_ids(limit).await? } else { ids };
        let mut outcomes = Vec::with_capacity(ids.len());
        for id in ids {
            let outcome = self.enforce_activity_deadline(&id).await;
            outcomes.push((id, outcome));
        }
        Ok(super::activity_deadlines::pass_report(outcomes))
    }

    /// `runInlinePass`, reported on the worker heartbeat.
    pub(super) async fn retention_inline_pass(&self) -> Result<Value> {
        // Closed workflow streams carry their own retention, whatever the
        // history retention mode (Server #774); the stream pass has its own
        // throttle and is not part of this report.
        if retention::inline_stream_pass_due(self.namespace.as_ref()) {
            let (streams, items) = super::workflow_streams::INLINE_SWEEP;
            if let Err(error) = self.prune_expired_streams(streams, items).await {
                eprintln!("workflow_stream_retention_failed: {error}");
            }
        }
        let policy = self.retention_policy().await?;
        if policy.forever {
            return Ok(retention::inline_report(false, None));
        }
        if !retention::inline_pass_due(self.namespace.as_ref()) {
            return Ok(retention::inline_report(true, None));
        }
        let report = self.prune_runs(&policy, 1, Vec::new()).await?;
        Ok(retention::inline_report(false, Some(&report)))
    }

    /// `runPass`: the given runs, or the oldest expired ones.
    async fn prune_runs(
        &self,
        policy: &retention::Policy,
        limit: i64,
        run_ids: Vec<String>,
    ) -> Result<serde_json::Map<String, Value>> {
        // `runPass` first retries committed deletions left pending.
        self.retry_payload_deletions(self.namespace.as_ref(), limit).await?;
        let run_ids = if run_ids.is_empty() { self.expired_run_ids(policy, limit).await? } else { run_ids };
        let mut outcomes = Vec::with_capacity(run_ids.len());
        for run_id in run_ids {
            let outcome = match self.prune_run(policy, &run_id).await {
                Ok(outcome) => outcome,
                Err(error) => retention::Outcome::Error(error.to_string()),
            };
            outcomes.push((run_id, outcome));
        }
        Ok(retention::report(outcomes))
    }

    /// `pruneRun`: one run of this namespace loses its details in one
    /// transaction, unless a hold keeps it.
    async fn prune_run(&self, policy: &retention::Policy, run_id: &str) -> Result<retention::Outcome> {
        use retention::Outcome::{Error, Pruned, Skipped};
        if policy.forever {
            return Ok(Skipped("namespace_retention_forever"));
        }
        let Some(summary) = Self::query("SELECT status,archived_at,workflow_instance_id FROM workflow_run_summaries WHERE id=$1 AND namespace=$2")
            .bind(run_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(Skipped("run_not_found"));
        };
        if !retention::terminal(&DB::string(&summary, "status")?) {
            return Ok(Skipped("run_not_terminal"));
        }
        if DB::optional_instant(&summary, "archived_at")?.is_some() {
            return Ok(Skipped("run_archived"));
        }
        // `DetachedActivityRetentionHold` keeps the run before its streams
        // are planned, so it marks nothing.
        if self.abandoned_activity_open(run_id).await? {
            return Ok(Skipped("detached_activity_still_open"));
        }
        // Any other skip or failure marks the instance's inbound streams
        // blocked, as PHP's `MessageStreamRetentionCleanup::markBlocked` does;
        // a later prune of the run clears the mark.
        let instance_id = DB::string(&summary, "workflow_instance_id")?;
        let outcome = match self.prune_unheld_run(run_id).await {
            Ok(outcome) => outcome,
            Err(error) => {
                self.mark_streams_blocked(&instance_id, run_id, "external_payload_cleanup_failed").await?;
                return Err(error);
            }
        };
        match &outcome {
            Skipped(reason) => self.mark_streams_blocked(&instance_id, run_id, reason).await?,
            Error(_) => self.mark_streams_blocked(&instance_id, run_id, "external_payload_cleanup_failed").await?,
            Pruned(..) => {}
        }
        Ok(outcome)
    }

    /// `pruneRun`'s transaction for a run no detached activity holds.
    async fn prune_unheld_run(&self, run_id: &str) -> Result<retention::Outcome> {
        use retention::Outcome::{Error, Pruned, Skipped};
        if let Some(reason) = self.retention_hold(run_id).await? {
            return Ok(Skipped(reason));
        }
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT status,closed_at,workflow_instance_id FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut *tx)
            .await?;
        // `assertPrunable`.
        if !retention::terminal(&DB::string(&run, "status")?) {
            return Ok(Error(format!("Workflow run [{run_id}] is not terminal.")));
        }
        if DB::optional_instant(&run, "closed_at")?.is_none() {
            return Ok(Error(format!("Workflow run [{run_id}] has not been closed.")));
        }
        // The run's external payloads, settled after its rows are deleted.
        let payloads = match self.retention_payload_plan(&mut tx, run_id).await? {
            Ok(plan) => plan,
            Err(outcome) => return Ok(outcome),
        };
        let mut deleted = serde_json::Map::new();
        deleted.insert("run_deleted".into(), json!(0));
        // Planned before the run's own summary goes, as PHP plans it.
        let streams = Self::stream_retention_plan(&mut tx, self.namespace.as_ref(), run_id, &DB::string(&run, "workflow_instance_id")?).await?;
        for (key, sql, binds) in retention::PRUNED {
            let mut query = Self::query(sql);
            for _ in 0..*binds {
                query = query.bind(run_id);
            }
            deleted.insert((*key).into(), json!(DB::affected(query.execute(&mut *tx).await?)));
        }
        for (key, sql) in retention::DURABLE_STREAMS {
            let affected = DB::affected(Self::query(sql).bind(run_id).execute(&mut *tx).await?);
            if let Some(key) = key {
                deleted.insert((*key).into(), json!(affected));
            }
        }
        let [streams_deleted, items_deleted, items_compacted] = Self::apply_stream_retention(&mut tx, run_id, &streams).await?;
        for (key, value) in retention::STREAM_COUNTS.into_iter().zip([streams_deleted, items_deleted, items_compacted]) {
            deleted.insert(key.into(), json!(value));
        }
        let Ok((external_payloads, staged)) = self.delete_retention_payloads(&mut tx, run_id, payloads).await else {
            return Ok(Error(retention::PAYLOAD_CLEANUP_FAILED.into()));
        };
        // The run's history is gone; no cached read of it may survive.
        tx.scope_history = None;
        Self::query("UPDATE workflow_runs SET details_pruned_at=$1 WHERE id=$2 AND details_pruned_at IS NULL")
            .bind(DB::bind_time(now()))
            .bind(run_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        // The prune has committed; only now may provider objects go.
        self.settle_payload_deletions(&staged).await;
        Ok(Pruned(deleted, external_payloads))
    }

    /// `MessageStreamRetentionCleanup::planForRun`: what pruning `run_id`
    /// releases of its instance's inbound message streams.
    ///
    /// An active or continued instance keeps its streams, cursor checkpoint,
    /// pending items and identity rows. An item the run consumed loses its
    /// payload only once a retained later run's cursor checkpoint covers it,
    /// so replay never needs it again. A terminal instance keeps all stream
    /// state until its last retained run goes, which removes every stream.
    async fn stream_retention_plan(
        tx: &mut DB::Connection,
        namespace: &str,
        run_id: &str,
        instance_id: &str,
    ) -> Result<retention::StreamPlan> {
        let streams = Self::query("SELECT id,cursor_position,cursor_checkpoint_run_id FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 ORDER BY id")
            .bind(namespace)
            .bind(instance_id)
            .fetch_all(&mut *tx)
            .await?;
        let mut plan = retention::StreamPlan::default();
        if streams.is_empty() {
            return Ok(plan);
        }
        let current = Self::query("SELECT current_run_id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(instance_id)
            .bind(namespace)
            .fetch_optional(&mut *tx)
            .await?
            .map(|row| DB::optional_string(&row, "current_run_id"))
            .transpose()?
            .flatten();
        // The current run counts only when its row exists, as in PHP: an
        // instance pointing at a missing run retains nothing through it.
        let current_status = match &current {
            Some(current) => Self::query("SELECT status FROM workflow_runs WHERE id=$1")
                .bind(current)
                .fetch_optional(&mut *tx)
                .await?
                .map(|row| DB::string(&row, "status"))
                .transpose()?,
            None => None,
        };
        let current_terminal = current_status.as_deref().is_some_and(retention::terminal);
        let current = current.filter(|_| current_status.is_some());
        let mut retained = Vec::new();
        for row in Self::query("SELECT id FROM workflow_run_summaries WHERE namespace=$1 AND workflow_instance_id=$2 AND id<>$3")
            .bind(namespace)
            .bind(instance_id)
            .bind(run_id)
            .fetch_all(&mut *tx)
            .await?
        {
            retained.push(DB::string(&row, "id")?);
        }
        for row in &streams {
            plan.stream_ids.push(DB::number(row, "id")?);
        }
        if current_terminal && retained.is_empty() {
            plan.delete_instance_state = true;
            return Ok(plan);
        }
        if let Some(current) = current.filter(|current| current != run_id && !retained.contains(current)) {
            retained.push(current);
        }
        for row in &streams {
            if DB::optional_string(row, "cursor_checkpoint_run_id")?.is_some_and(|checkpoint| retained.contains(&checkpoint)) {
                plan.checkpointed.push((DB::number(row, "id")?, DB::number(row, "cursor_position")?));
            }
        }
        Ok(plan)
    }

    /// `MessageStreamRetentionCleanup::apply`, in the prune's transaction:
    /// streams deleted, items deleted and items compacted.
    async fn apply_stream_retention(tx: &mut DB::Connection, run_id: &str, plan: &retention::StreamPlan) -> Result<[u64; 3]> {
        if plan.stream_ids.is_empty() {
            return Ok([0, 0, 0]);
        }
        let mut counts = [0, 0, 0];
        if plan.delete_instance_state {
            for stream_id in &plan.stream_ids {
                counts[1] += DB::affected(Self::query("DELETE FROM workflow_inbound_stream_items WHERE stream_id=$1").bind(*stream_id).execute(&mut *tx).await?);
                counts[0] += DB::affected(Self::query("DELETE FROM workflow_inbound_streams WHERE id=$1").bind(*stream_id).execute(&mut *tx).await?);
            }
            return Ok(counts);
        }
        let at = now();
        for (stream_id, cursor) in &plan.checkpointed {
            counts[2] += DB::affected(
                Self::query("UPDATE workflow_inbound_stream_items SET payload_blob='',payload_released_at=$1,updated_at=$2 WHERE stream_id=$3 AND consumed_run_id=$4 AND consumed_at IS NOT NULL AND payload_released_at IS NULL AND position<=$5")
                    .bind(DB::bind_time(at))
                    .bind(DB::bind_time(at))
                    .bind(*stream_id)
                    .bind(run_id)
                    .bind(*cursor)
                    .execute(&mut *tx)
                    .await?,
            );
        }
        for stream_id in &plan.stream_ids {
            Self::query("UPDATE workflow_inbound_streams SET cleanup_blocked_at=NULL,cleanup_blocked_reason=NULL,cleanup_blocked_run_id=NULL,updated_at=$1 WHERE id=$2 AND cleanup_blocked_run_id=$3")
                .bind(DB::bind_time(at))
                .bind(*stream_id)
                .bind(run_id)
                .execute(&mut *tx)
                .await?;
        }
        Ok(counts)
    }

    /// `MessageStreamRetentionCleanup::markBlocked`: every inbound stream of
    /// the run's instance records why the run's prune did not complete.
    async fn mark_streams_blocked(&self, instance_id: &str, run_id: &str, reason: &str) -> Result<()> {
        let at = now();
        let reason: String = reason.chars().take(64).collect();
        Self::query("UPDATE workflow_inbound_streams SET cleanup_blocked_at=$1,cleanup_blocked_reason=$2,cleanup_blocked_run_id=$3,updated_at=$4 WHERE namespace=$5 AND workflow_instance_id=$6")
            .bind(DB::bind_time(at))
            .bind(reason)
            .bind(run_id)
            .bind(DB::bind_time(at))
            .bind(self.namespace.as_ref())
            .bind(instance_id)
            .execute(&self.pool)
            .await?;
        Ok(())
    }

    /// What keeps a closed run's details before its transaction: an external
    /// payload reference on an inbound or durable stream, which native does
    /// not settle. The run's other references are settled in its transaction
    /// (`retention_payloads`).
    async fn retention_hold(&self, run_id: &str) -> Result<Option<&'static str>> {
        for (sql, binds, reason) in retention::HOLDS {
            let mut query = Self::scalar(sql);
            for _ in 0..*binds {
                query = query.bind(run_id);
            }
            if query.fetch_one(&self.pool).await? > 0 {
                return Ok(Some(reason));
            }
        }
        if Self::scalar(DB::RUN_STREAM_REFERENCES).bind(run_id).bind(run_id).fetch_one(&self.pool).await? > 0 {
            return Ok(Some("external_payload_cleanup_unavailable"));
        }
        Ok(None)
    }

    /// `WorkflowRunRetentionCleanup::retentionHoldReason`: an activity
    /// scheduled under the `abandon` cancellation policy whose history has no
    /// terminal event for its last attempt (`ActivityAbandonment::hasTerminalHistory`).
    /// Other activities left open when a run closed do not hold it.
    async fn abandoned_activity_open(&self, run_id: &str) -> Result<bool> {
        let rows = Self::query("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityCompleted','ActivityFailed','ActivityCancelled','ActivityTimedOut') ORDER BY sequence")
            .bind(run_id).fetch_all(&self.pool).await?;
        let mut events = Vec::with_capacity(rows.len());
        for row in &rows {
            events.push((DB::number(row, "sequence")?, DB::string(row, "event_type")?, DB::document_row(row, "payload")?));
        }
        Ok(events.iter().any(|(sequence, kind, payload)| {
            kind == "ActivityScheduled"
                && payload["activity"]["cancellation_policy"] == "abandon"
                && !abandoned_activity_closed(&events, *sequence, payload)
        }))
    }
}

/// `ActivityAbandonment::hasTerminalHistory` over a run's activity events.
fn abandoned_activity_closed(events: &[(i64, String, Value)], scheduled_at: i64, scheduled: &Value) -> bool {
    let Some(execution) = scheduled["activity_execution_id"].as_str() else {
        return false;
    };
    let Some(sequence) = scheduled["sequence"].as_i64().filter(|sequence| *sequence >= 1) else {
        return false;
    };
    if scheduled["activity"]["id"] != execution {
        return false;
    }
    let started = events.iter().rev().find(|(_, kind, payload)| kind == "ActivityStarted" && payload["activity_execution_id"] == execution);
    let after = started.map_or(scheduled_at, |(at, _, _)| *at);
    let attempt = started.map_or(&Value::Null, |(_, _, payload)| &payload["activity_attempt_id"]);
    events.iter().any(|(at, kind, payload)| {
        matches!(kind.as_str(), "ActivityCompleted" | "ActivityFailed" | "ActivityCancelled" | "ActivityTimedOut")
            && payload["activity_execution_id"] == execution
            && payload["sequence"] == sequence
            && (started.is_some() || matches!(kind.as_str(), "ActivityCancelled" | "ActivityTimedOut"))
            && *at > after
            && payload.get("activity_attempt_id").is_some()
            && &payload["activity_attempt_id"] == attempt
    })
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
    pub(crate) activity_task_lease_seconds: i64,
    pub(crate) abandoned_upload_expiry_seconds: i64,
    /// The honored signal, update and payload ceilings.
    pub(crate) structural: super::StructuralLimits,
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

/// `StructuralLimits::snapshot`: honored ceilings as configured, where a
/// disabled one reads 0 (`intConfig`'s `max(0, value)`).
fn structural_limits(connection: &str, limits: &super::StructuralLimits, memo: Option<usize>) -> Value {
    let shown = |limit: Option<usize>| limit.unwrap_or(0);
    let configured = json!({
        "pending_activity_count": shown(limits.pending_activities),
        "pending_child_count": shown(limits.pending_children),
        "pending_timer_count": shown(limits.pending_timers),
        "pending_signal_count": shown(limits.pending_signals),
        "pending_update_count": shown(limits.pending_updates),
        "command_batch_size": 1000,
        "payload_size_bytes": shown(limits.payload_bytes),
        "memo_size_bytes": shown(memo),
        "search_attribute_size_bytes": 40960,
        "history_transaction_size": shown(limits.history_events),
        "history_transaction_bytes": shown(limits.history_bytes),
        "history_transaction_rows": shown(limits.history_rows),
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
fn backend_capabilities(now: DateTime<Utc>, connection: &str, structural: &super::StructuralLimits, memo: Option<usize>) -> Value {
    // The active ceilings, with the backend's adjustments and issues.
    let limits = structural_limits(connection, structural, memo);
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
/// The grouped candidate rows of one repair candidate query.
pub(super) fn scope_rows<DB: Backend>(rows: Vec<DB::Row>) -> Result<Vec<ScopeRow>>
where
    usize: ColumnIndex<DB::Row>,
    for<'r> i64: Decode<'r, DB>,
{
    rows.iter()
        .map(|row| {
            Ok(ScopeRow {
                connection: DB::optional_string(row, "connection")?,
                queue: DB::optional_string(row, "queue")?,
                compatibility: DB::optional_string(row, "compatibility")?,
                count: usize::try_from(DB::number(row, "candidate_count")?).unwrap_or(0),
                oldest: DB::optional_instant(row, "oldest_candidate_at")?,
            })
        })
        .collect()
}

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
            "activity_task_lease_seconds": config.activity_task_lease_seconds,
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

    // `ActivityAbandonment::hasTerminalHistory`: the terminal event must follow
    // the last start and name its attempt; a never-started activity closes
    // only by cancellation or timeout.
    #[test]
    fn an_abandoned_activity_closes_with_its_last_attempt() {
        let scheduled = json!({"activity_execution_id":"a","sequence":2,"activity":{"id":"a","cancellation_policy":"abandon"}});
        let event = |at: i64, kind: &str, payload: Value| (at, kind.to_owned(), payload);
        let started = event(4, "ActivityStarted", json!({"activity_execution_id":"a","activity_attempt_id":"t1"}));
        let completed = |attempt: &str| event(5, "ActivityCompleted", json!({"activity_execution_id":"a","sequence":2,"activity_attempt_id":attempt}));
        let open = [event(3, "ActivityScheduled", scheduled.clone()), started.clone()];
        assert!(!abandoned_activity_closed(&open, 3, &scheduled));
        let closed = [event(3, "ActivityScheduled", scheduled.clone()), started.clone(), completed("t1")];
        assert!(abandoned_activity_closed(&closed, 3, &scheduled));
        let other_attempt = [event(3, "ActivityScheduled", scheduled.clone()), started, completed("t0")];
        assert!(!abandoned_activity_closed(&other_attempt, 3, &scheduled));
        let unstarted = [event(3, "ActivityScheduled", scheduled.clone()), completed("t1")];
        assert!(!abandoned_activity_closed(&unstarted, 3, &scheduled));
        let timed_out = [event(3, "ActivityScheduled", scheduled.clone()),
            event(5, "ActivityTimedOut", json!({"activity_execution_id":"a","sequence":2,"activity_attempt_id":null}))];
        assert!(abandoned_activity_closed(&timed_out, 3, &scheduled));
    }

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
