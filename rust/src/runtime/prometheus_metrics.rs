//! `GET /api/system/prometheus-metrics`, as published Server 2.11 serves it
//! (`PrometheusMetricsSummary::snapshot`): the namespace's workflow, activity
//! and task-queue series for Prometheus scrapers, each family bounded with its
//! suppression disclosed.
//!
//! Native refuses the series-limit settings, so every family uses PHP's
//! default limit. Latency buckets compare each duration with a numeric bound
//! on every backend. Published PHP binds the bounds as text, which SQLite
//! orders after every number, so PHP on SQLite counts every duration in every
//! bucket (server#683); native reports the cumulative histogram there too.
//! Task and worker times compare with whole seconds, as Laravel binds them.
use super::Result;
use super::operator_metrics::whole_second;
use super::store::now;
use chrono::{DateTime, Duration, Utc};
use serde_json::{Map, Value, json};
use std::collections::HashMap;

/// PHP's default series limit for each family.
const SERIES_LIMIT: usize = 100;

/// `LATENCY_BUCKETS_SECONDS`, keyed as PHP casts each bound to a string.
const BUCKETS: [(&str, f64); 10] = [
    ("0.1", 0.1),
    ("0.5", 0.5),
    ("1", 1.0),
    ("2.5", 2.5),
    ("5", 5.0),
    ("10", 10.0),
    ("30", 30.0),
    ("60", 60.0),
    ("300", 300.0),
    ("900", 900.0),
];

const WORKFLOW_SELECTION: &str = "bounded_task_queue_and_workflow_type_ascending";
const ACTIVITY_SELECTION: &str = "bounded_task_queue_workflow_type_and_activity_type_ascending";
const QUEUE_SELECTION: &str = "task_queue_name_ascending";

/// Cumulative bucket counts of a duration expression, one column per bound.
macro_rules! buckets {
    ($available:literal, $duration:literal) => {
        concat!(
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=0.1 THEN 1 END) AS bucket_0,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=0.5 THEN 1 END) AS bucket_1,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=1 THEN 1 END) AS bucket_2,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=2.5 THEN 1 END) AS bucket_3,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=5 THEN 1 END) AS bucket_4,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=10 THEN 1 END) AS bucket_5,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=30 THEN 1 END) AS bucket_6,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=60 THEN 1 END) AS bucket_7,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=300 THEN 1 END) AS bucket_8,",
            "COUNT(CASE WHEN ", $available, " AND ", $duration, "<=900 THEN 1 END) AS bucket_9"
        )
    };
}

/// The workflow series up to and including the last candidate (queue, type).
macro_rules! workflow_rows {
    ($float:literal) => {
        concat!(
            "SELECT COALESCE(queue,'default') AS task_queue,workflow_type,COUNT(*) AS started_total,",
            "COUNT(CASE WHEN status='completed' THEN 1 END) AS completed_total,",
            "COUNT(CASE WHEN status='failed' THEN 1 END) AS failed_total,",
            "COUNT(CASE WHEN status='cancelled' THEN 1 END) AS cancelled_total,",
            "COUNT(CASE WHEN status='terminated' THEN 1 END) AS terminated_total,",
            "COUNT(CASE WHEN status_bucket='running' THEN 1 END) AS running,",
            "COUNT(duration_ms) AS latency_count,",
            "CAST(COALESCE(SUM(duration_ms/1000.0),0) AS ", $float, ") AS latency_sum,",
            buckets!("duration_ms IS NOT NULL", "duration_ms/1000.0"),
            " FROM workflow_run_summaries WHERE namespace=$1",
            " AND (COALESCE(queue,'default')<$2 OR (COALESCE(queue,'default')=$3 AND workflow_type<=$4))",
            " GROUP BY COALESCE(queue,'default'),workflow_type"
        )
    };
}

/// The activity series up to and including the last candidate key.
macro_rules! activity_rows {
    ($float:literal, $duration:literal) => {
        concat!(
            "SELECT COALESCE(activity_executions.queue,workflow_runs.queue,'default') AS task_queue,",
            "workflow_runs.workflow_type AS workflow_type,activity_executions.activity_type AS activity_type,",
            "COUNT(*) AS started_total,",
            "COUNT(CASE WHEN activity_executions.status='completed' THEN 1 END) AS completed_total,",
            "COUNT(CASE WHEN activity_executions.status='failed' THEN 1 END) AS failed_total,",
            "COUNT(CASE WHEN activity_executions.status='cancelled' THEN 1 END) AS cancelled_total,",
            "COUNT(CASE WHEN activity_executions.status IN ('pending','running') THEN 1 END) AS running,",
            "COUNT(CASE WHEN activity_executions.started_at IS NOT NULL AND activity_executions.closed_at IS NOT NULL THEN 1 END) AS latency_count,",
            "CAST(COALESCE(SUM(CASE WHEN activity_executions.started_at IS NOT NULL AND activity_executions.closed_at IS NOT NULL THEN ",
            $duration,
            " END),0) AS ",
            $float,
            ") AS latency_sum,",
            buckets!(
                "activity_executions.started_at IS NOT NULL AND activity_executions.closed_at IS NOT NULL",
                $duration
            ),
            " FROM activity_executions JOIN workflow_runs ON workflow_runs.id=activity_executions.workflow_run_id",
            " WHERE workflow_runs.namespace=$1 AND (COALESCE(activity_executions.queue,workflow_runs.queue,'default')<$2",
            " OR (COALESCE(activity_executions.queue,workflow_runs.queue,'default')=$3 AND (workflow_runs.workflow_type<$4",
            " OR (workflow_runs.workflow_type=$5 AND activity_executions.activity_type<=$6))))",
            " GROUP BY COALESCE(activity_executions.queue,workflow_runs.queue,'default'),workflow_runs.workflow_type,activity_executions.activity_type"
        )
    };
}

/// `whereTaskRuntimeVisible`: open work, or work created or dispatched within
/// the reporting minute.
macro_rules! visible_tasks {
    ($namespace:literal, $created:literal, $dispatched:literal) => {
        concat!(
            " FROM workflow_tasks WHERE namespace=", $namespace,
            " AND task_type IN ('workflow','activity') AND (status IN ('ready','leased') OR created_at>=", $created,
            " OR (last_dispatched_at IS NOT NULL AND last_dispatched_at>=", $dispatched, "))"
        )
    };
}

/// A float as PHP's `json_encode` writes it: whole values without a fraction.
fn php_float(value: f64) -> Value {
    if value.fract() == 0.0 && value.abs() < 9.0e15 {
        json!(value as i64)
    } else {
        json!(value)
    }
}

/// PHP's `round($value, 6)` for the finite sums reported here.
fn round6(value: f64) -> f64 {
    (value * 1_000_000.0).round() / 1_000_000.0
}

/// `seriesSnapshot`.
struct Snapshot {
    series: Vec<Value>,
    observed: usize,
    suppressed_started: i64,
    lower_bound: bool,
}

impl Snapshot {
    fn precision(&self) -> &'static str {
        if self.lower_bound { "lower_bound" } else { "exact" }
    }
    fn suppressed(&self) -> usize {
        self.observed.saturating_sub(self.series.len())
    }
    /// `seriesLimitDisclosure`.
    fn disclosure(&self, dimensions: &[&str], selection: &str) -> Value {
        json!({
            "label_dimensions": dimensions, "limit": SERIES_LIMIT,
            "observed_series_count": self.observed, "observed_series_count_precision": self.precision(),
            "reported_series_count": self.series.len(), "suppressed_series_count": self.suppressed(),
            "suppressed_started_total": self.suppressed_started, "suppressed_counts_precision": self.precision(),
            "truncated": self.observed > self.series.len(), "selection": selection,
        })
    }
}

/// `BoundedMetricPolicy::labelSet` for a family's bounded dimensions.
fn label_set(dimensions: &[&str], selection: &str) -> Value {
    let mut set = Map::new();
    set.insert("namespace".into(), json!("request_scope_not_label"));
    for dimension in dimensions {
        set.insert(
            (*dimension).to_owned(),
            json!({"cardinality_class": "bounded_series", "limit": SERIES_LIMIT, "selection": selection}),
        );
    }
    Value::Object(set)
}

fn empty_task_queue(queue: &str) -> Map<String, Value> {
    let mut entry = Map::new();
    entry.insert("task_queue".into(), json!(queue));
    for prefix in ["workflow", "activity"] {
        for field in [
            "ready_tasks",
            "ready_due_tasks",
            "delayed_tasks",
            "leased_tasks",
            "expired_leases",
            "tasks_added_last_minute",
            "tasks_dispatched_last_minute",
        ] {
            entry.insert(format!("{prefix}_{field}"), json!(0));
        }
    }
    for field in [
        "active_pollers",
        "workflow_slot_capacity",
        "activity_slot_capacity",
        "available_workflow_slots",
        "available_activity_slots",
    ] {
        entry.insert(field.into(), json!(0));
    }
    entry
}

fn add(entry: &mut Map<String, Value>, field: &str, amount: i64) {
    let current = entry.get(field).and_then(Value::as_i64).unwrap_or_default();
    entry.insert(field.to_owned(), json!(current + amount));
}

store_methods! {
    pub(crate) async fn prometheus_metrics(&self) -> Result<Value> {
        let now = now();
        let workflows = self.workflow_series().await?;
        let activities = self.activity_series().await?;
        let queues = self.task_queue_series(now).await?;
        let families = [&workflows, &activities, &queues];
        let precision = if families.iter().any(|family| family.lower_bound) { "lower_bound" } else { "exact" };
        let series_count: usize = families.iter().map(|family| family.series.len()).sum();
        let observed: usize = families.iter().map(|family| family.observed).sum();
        let suppressed: usize = families.iter().map(|family| family.suppressed()).sum();
        let workflow_dimensions = ["task_queue", "workflow_type"];
        let activity_dimensions = ["task_queue", "workflow_type", "activity_type"];
        let series_limits = json!({
            "workflows": workflows.disclosure(&workflow_dimensions, WORKFLOW_SELECTION),
            "activities": activities.disclosure(&activity_dimensions, ACTIVITY_SELECTION),
            "task_queues": queues.disclosure(&["task_queue"], QUEUE_SELECTION),
        });
        let workflow_labels = label_set(&workflow_dimensions, WORKFLOW_SELECTION);
        let activity_labels = label_set(&activity_dimensions, ACTIVITY_SELECTION);
        Ok(json!({
            "schema": "durable-workflow.prometheus-metrics.v1",
            "generated_at": now.to_rfc3339_opts(chrono::SecondsFormat::Micros, true),
            "namespace": self.namespace.as_ref(),
            "latency_buckets_seconds": BUCKETS.iter().map(|(_, bound)| php_float(*bound)).collect::<Vec<_>>(),
            "series": {"workflows": workflows.series, "activities": activities.series, "task_queues": queues.series},
            "series_count": series_count,
            "observed_series_count": observed,
            "observed_series_count_precision": precision,
            "suppressed_series_count": suppressed,
            "suppressed_series_count_precision": precision,
            "cardinality": {
                "series_limits": series_limits,
                "metric_label_sets": {
                    "dw_workflow_runs_total": workflow_labels,
                    "dw_workflow_run_latency_seconds": workflow_labels,
                    "dw_activity_executions_total": activity_labels,
                    "dw_activity_execution_latency_seconds": activity_labels,
                    "dw_task_queue_runtime_state": label_set(&["task_queue"], QUEUE_SELECTION),
                },
            },
        }))
    }

    /// The counters and latency histogram shared by both runtime families.
    fn runtime_series(row: &DB::Row, labels: Map<String, Value>, terminated: bool) -> Result<Value> {
        let mut series = labels;
        for field in ["started_total", "completed_total", "failed_total", "cancelled_total"] {
            series.insert(field.into(), json!(DB::number(row, field)?));
        }
        if terminated {
            series.insert("terminated_total".into(), json!(DB::number(row, "terminated_total")?));
        }
        series.insert("running".into(), json!(DB::number(row, "running")?));
        let mut buckets = Map::new();
        for (index, (bound, _)) in BUCKETS.iter().enumerate() {
            buckets.insert((*bound).to_owned(), json!(DB::number(row, &format!("bucket_{index}"))?));
        }
        let sum = DB::optional_float(row, "latency_sum")?.unwrap_or_default();
        series.insert("latency_seconds".into(), json!({
            "count": DB::number(row, "latency_count")?, "sum": php_float(round6(sum)), "buckets": buckets,
        }));
        Ok(Value::Object(series))
    }

    /// `workflowSeries`: the first series by task queue and workflow type.
    async fn workflow_series(&self) -> Result<Snapshot> {
        let candidates: Vec<(String, String)> = Self::query(
            "SELECT DISTINCT COALESCE(queue,'default') AS task_queue,workflow_type FROM workflow_run_summaries WHERE namespace=$1 ORDER BY task_queue,workflow_type LIMIT 101",
        )
        .bind(self.namespace.as_ref())
        .fetch_all(&self.pool)
        .await?
        .iter()
        .map(|row| Ok((DB::string(row, "task_queue")?, DB::optional_string(row, "workflow_type")?.unwrap_or_default())))
        .collect::<Result<_>>()?;
        let Some((last_queue, last_type)) = candidates.last().cloned() else {
            return Ok(Snapshot { series: Vec::new(), observed: 0, suppressed_started: 0, lower_bound: false });
        };
        let sql = match DB::NAME {
            "PostgreSQL" => workflow_rows!("DOUBLE PRECISION"),
            "MySQL" => workflow_rows!("DOUBLE"),
            _ => workflow_rows!("REAL"),
        };
        let rows = Self::query(sql)
            .bind(self.namespace.as_ref())
            .bind(last_queue.clone())
            .bind(last_queue)
            .bind(last_type)
            .fetch_all(&self.pool)
            .await?;
        let mut keyed = HashMap::new();
        for row in &rows {
            let key = (DB::string(row, "task_queue")?, DB::optional_string(row, "workflow_type")?.unwrap_or_default());
            keyed.insert(key, row);
        }
        let mut series = Vec::new();
        for key in candidates.iter().take(SERIES_LIMIT) {
            if let Some(row) = keyed.get(key) {
                let mut labels = Map::new();
                labels.insert("task_queue".into(), json!(key.0));
                labels.insert("workflow_type".into(), json!(key.1));
                series.push(Self::runtime_series(row, labels, true)?);
            }
        }
        let mut suppressed_started = 0;
        for key in candidates.iter().skip(SERIES_LIMIT) {
            if let Some(row) = keyed.get(key) {
                suppressed_started += DB::number(row, "started_total")?;
            }
        }
        Ok(Snapshot { series, observed: candidates.len(), suppressed_started, lower_bound: candidates.len() > SERIES_LIMIT })
    }

    /// `activitySeries`: the first series by task queue, workflow type and
    /// activity type.
    async fn activity_series(&self) -> Result<Snapshot> {
        let candidates: Vec<(String, String, String)> = Self::query(
            "SELECT DISTINCT COALESCE(activity_executions.queue,workflow_runs.queue,'default') AS task_queue,workflow_runs.workflow_type AS workflow_type,activity_executions.activity_type AS activity_type FROM activity_executions JOIN workflow_runs ON workflow_runs.id=activity_executions.workflow_run_id WHERE workflow_runs.namespace=$1 ORDER BY task_queue,workflow_type,activity_type LIMIT 101",
        )
        .bind(self.namespace.as_ref())
        .fetch_all(&self.pool)
        .await?
        .iter()
        .map(|row| {
            Ok((
                DB::string(row, "task_queue")?,
                DB::optional_string(row, "workflow_type")?.unwrap_or_default(),
                DB::optional_string(row, "activity_type")?.unwrap_or_default(),
            ))
        })
        .collect::<Result<_>>()?;
        let Some((last_queue, last_type, last_activity)) = candidates.last().cloned() else {
            return Ok(Snapshot { series: Vec::new(), observed: 0, suppressed_started: 0, lower_bound: false });
        };
        let sql = match DB::NAME {
            "PostgreSQL" => activity_rows!(
                "DOUBLE PRECISION",
                "EXTRACT(EPOCH FROM (activity_executions.closed_at-activity_executions.started_at))"
            ),
            "MySQL" => activity_rows!(
                "DOUBLE",
                "TIMESTAMPDIFF(MICROSECOND,activity_executions.started_at,activity_executions.closed_at)/1000000.0"
            ),
            _ => activity_rows!(
                "REAL",
                "(julianday(activity_executions.closed_at)-julianday(activity_executions.started_at))*86400.0"
            ),
        };
        let rows = Self::query(sql)
            .bind(self.namespace.as_ref())
            .bind(last_queue.clone())
            .bind(last_queue)
            .bind(last_type.clone())
            .bind(last_type)
            .bind(last_activity)
            .fetch_all(&self.pool)
            .await?;
        let mut keyed = HashMap::new();
        for row in &rows {
            let key = (
                DB::string(row, "task_queue")?,
                DB::optional_string(row, "workflow_type")?.unwrap_or_default(),
                DB::optional_string(row, "activity_type")?.unwrap_or_default(),
            );
            keyed.insert(key, row);
        }
        let mut series = Vec::new();
        for key in candidates.iter().take(SERIES_LIMIT) {
            if let Some(row) = keyed.get(key) {
                let mut labels = Map::new();
                labels.insert("task_queue".into(), json!(key.0));
                labels.insert("workflow_type".into(), json!(key.1));
                labels.insert("activity_type".into(), json!(key.2));
                series.push(Self::runtime_series(row, labels, false)?);
            }
        }
        let mut suppressed_started = 0;
        for key in candidates.iter().skip(SERIES_LIMIT) {
            if let Some(row) = keyed.get(key) {
                suppressed_started += DB::number(row, "started_total")?;
            }
        }
        Ok(Snapshot { series, observed: candidates.len(), suppressed_started, lower_bound: candidates.len() > SERIES_LIMIT })
    }

    /// `taskQueueSeries`: open and recent task counts and fresh worker
    /// capacity for the first task queues by name.
    async fn task_queue_series(&self, now: DateTime<Utc>) -> Result<Snapshot> {
        // Laravel binds these times in whole seconds, as operator metrics do.
        let bound = whole_second(now);
        let minute_ago = whole_second(now - Duration::minutes(1));
        let stale_cutoff = whole_second(now - Duration::seconds(self.worker_stale_after_seconds));
        let mut queues: Vec<String> = Vec::new();
        for row in Self::query(concat!("SELECT DISTINCT COALESCE(queue,'default') AS task_queue", visible_tasks!("$1", "$2", "$3"), " ORDER BY task_queue LIMIT 101"))
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(minute_ago))
            .bind(DB::bind_time(minute_ago))
            .fetch_all(&self.pool)
            .await?
        {
            queues.push(DB::string(&row, "task_queue")?);
        }
        for row in Self::query("SELECT DISTINCT COALESCE(task_queue,'default') AS task_queue FROM workflow_worker_registrations WHERE namespace=$1 AND status='active' AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at>=$2 ORDER BY task_queue LIMIT 101")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(stale_cutoff))
            .fetch_all(&self.pool)
            .await?
        {
            queues.push(DB::string(&row, "task_queue")?);
        }
        queues.sort();
        queues.dedup();
        queues.truncate(SERIES_LIMIT + 1);
        let observed = queues.len();
        queues.truncate(SERIES_LIMIT);
        let mut entries: std::collections::BTreeMap<String, Map<String, Value>> = std::collections::BTreeMap::new();
        let rows = Self::query(concat!(
            "SELECT COALESCE(queue,'default') AS task_queue,task_type,status,COUNT(*) AS n,",
            "COUNT(CASE WHEN status='ready' AND available_at>$1 THEN 1 END) AS delayed_count,",
            "COUNT(CASE WHEN status='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=$2 THEN 1 END) AS expired_lease_count,",
            "COUNT(CASE WHEN created_at>=$3 THEN 1 END) AS added_last_minute,",
            "COUNT(CASE WHEN last_dispatched_at IS NOT NULL AND last_dispatched_at>=$4 THEN 1 END) AS dispatched_last_minute",
            visible_tasks!("$5", "$6", "$7"),
            " GROUP BY COALESCE(queue,'default'),task_type,status"
        ))
        .bind(DB::bind_time(bound))
        .bind(DB::bind_time(bound))
        .bind(DB::bind_time(minute_ago))
        .bind(DB::bind_time(minute_ago))
        .bind(self.namespace.as_ref())
        .bind(DB::bind_time(minute_ago))
        .bind(DB::bind_time(minute_ago))
        .fetch_all(&self.pool)
        .await?;
        for row in &rows {
            let queue = DB::string(row, "task_queue")?;
            if !queues.contains(&queue) {
                continue;
            }
            let entry = entries.entry(queue.clone()).or_insert_with(|| empty_task_queue(&queue));
            let prefix = if DB::string(row, "task_type")? == "activity" { "activity" } else { "workflow" };
            let status = DB::string(row, "status")?;
            let count = DB::number(row, "n")?;
            if status == "ready" {
                let delayed = DB::number(row, "delayed_count")?;
                add(entry, &format!("{prefix}_ready_tasks"), count);
                add(entry, &format!("{prefix}_delayed_tasks"), delayed);
                add(entry, &format!("{prefix}_ready_due_tasks"), (count - delayed).max(0));
            }
            if status == "leased" {
                add(entry, &format!("{prefix}_leased_tasks"), count);
                add(entry, &format!("{prefix}_expired_leases"), DB::number(row, "expired_lease_count")?);
            }
            add(entry, &format!("{prefix}_tasks_added_last_minute"), DB::number(row, "added_last_minute")?);
            add(entry, &format!("{prefix}_tasks_dispatched_last_minute"), DB::number(row, "dispatched_last_minute")?);
        }
        // Capacity is summed here rather than in SQL, where MySQL's SUM is a
        // decimal.
        let workers = Self::query("SELECT COALESCE(task_queue,'default') AS task_queue,max_concurrent_workflow_tasks,max_concurrent_activity_tasks,available_workflow_slots,available_activity_slots FROM workflow_worker_registrations WHERE namespace=$1 AND status='active' AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at>=$2")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(stale_cutoff))
            .fetch_all(&self.pool)
            .await?;
        let mut capacity: HashMap<String, [i64; 5]> = HashMap::new();
        for row in &workers {
            let queue = DB::string(row, "task_queue")?;
            if !queues.contains(&queue) {
                continue;
            }
            let workflow = DB::optional_number(row, "max_concurrent_workflow_tasks")?;
            let activity = DB::optional_number(row, "max_concurrent_activity_tasks")?;
            let totals = capacity.entry(queue).or_default();
            totals[0] += 1;
            totals[1] += workflow.unwrap_or_default();
            totals[2] += activity.unwrap_or_default();
            totals[3] += DB::optional_number(row, "available_workflow_slots")?.or(workflow).unwrap_or_default();
            totals[4] += DB::optional_number(row, "available_activity_slots")?.or(activity).unwrap_or_default();
        }
        for (queue, totals) in capacity {
            let entry = entries.entry(queue.clone()).or_insert_with(|| empty_task_queue(&queue));
            for (field, value) in [
                "active_pollers",
                "workflow_slot_capacity",
                "activity_slot_capacity",
                "available_workflow_slots",
                "available_activity_slots",
            ]
            .iter()
            .zip(totals)
            {
                entry.insert((*field).to_owned(), json!(value));
            }
        }
        Ok(Snapshot {
            series: entries.into_values().map(Value::Object).collect(),
            observed,
            suppressed_started: 0,
            lower_bound: observed > SERIES_LIMIT,
        })
    }
}
