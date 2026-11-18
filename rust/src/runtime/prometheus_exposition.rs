//! `GET /metrics` (Server #534): the requested namespace's task queue
//! backlog, admission, activity retry pressure, schedule state and run and
//! activity series in Prometheus text format 0.0.4, for Prometheus, KEDA and
//! HPA adapters.
//!
//! Workers poll for tasks, so a worker Deployment scaled to zero emits
//! nothing. The backlog here is read from the Server's own task rows, so due
//! work is visible while no worker runs, and it stays distinct from future
//! (delayed) work, leased work and the absence of pollers.
//!
//! Every label value is a namespace, task queue, task kind, build id,
//! workflow type, activity type or a fixed state name; workflow, run and task
//! identifiers never become labels. Each family is bounded: at most
//! [`SERIES_LIMIT`] task queues (by name), [`BUILD_LIMIT`] build ids per
//! queue and kind (further build ids are summed under [`OTHER_BUILDS`]), and
//! the workflow and activity families' own limits. Truncation is disclosed
//! by `dw_metrics_series_truncated`.
//!
//! A failed read fails the whole scrape (the route answers 503), so missing
//! telemetry is never rendered as an empty queue or unlimited capacity.
use super::{BUCKETS, SERIES_LIMIT, Snapshot};
use crate::runtime::Result;
use crate::runtime::store::now;
use chrono::{DateTime, Duration, Utc};
use serde_json::Value;
use std::collections::{BTreeMap, BTreeSet};
use std::fmt::Write as _;

/// Build ids reported per task queue and kind before the rest are summed.
const BUILD_LIMIT: usize = 10;
/// The build id label of work beyond [`BUILD_LIMIT`] build ids.
const OTHER_BUILDS: &str = "__other__";
const KINDS: [&str; 2] = ["workflow", "activity"];
const ADMISSION_STATES: [&str; 3] = ["accepting", "throttled", "unlimited"];

/// Ready and leased workflow and activity tasks by queue, kind and effective
/// build id. Rows of queues beyond the reported ones are dropped after the
/// read: a SQL bound on the queue name would follow the database's collation,
/// not the byte order the queues are chosen in. A workflow task's build id is its own
/// compatibility, else its run's, as the build-id rollout view groups it;
/// activity tasks are not routed by build id and carry none. The oldest due
/// task's readiness is its ready origin, or its availability when that is
/// later; tasks without a recorded origin use their creation time.
const BACKLOG_SQL: &str = concat!(
    "SELECT COALESCE(t.queue,'default') AS task_queue,t.task_type AS task_kind,",
    "CASE WHEN t.task_type='workflow' THEN COALESCE(NULLIF(TRIM(t.compatibility),''),NULLIF(TRIM(r.compatibility),''),'') ELSE '' END AS build_id,",
    "COUNT(CASE WHEN t.status='ready' THEN 1 END) AS ready_count,",
    "COUNT(CASE WHEN t.status='ready' AND (t.available_at IS NULL OR t.available_at<=$1) THEN 1 END) AS due_count,",
    "COUNT(CASE WHEN t.status='leased' THEN 1 END) AS leased_count,",
    "COUNT(CASE WHEN t.status='leased' AND t.lease_expires_at IS NOT NULL AND t.lease_expires_at<=$2 THEN 1 END) AS expired_count,",
    "MIN(CASE WHEN t.status='ready' AND (t.available_at IS NULL OR t.available_at<=$3) THEN ",
    "CASE WHEN t.available_at IS NOT NULL AND (t.ready_origin_at IS NULL OR t.available_at>t.ready_origin_at) THEN t.available_at ",
    "ELSE COALESCE(t.ready_origin_at,t.created_at) END END) AS oldest_due_at",
    " FROM workflow_tasks t LEFT JOIN workflow_runs r ON r.id=t.workflow_run_id",
    " WHERE t.namespace=$4 AND t.task_type IN ('workflow','activity') AND t.status IN ('ready','leased')",
    " GROUP BY COALESCE(t.queue,'default'),t.task_type,",
    "CASE WHEN t.task_type='workflow' THEN COALESCE(NULLIF(TRIM(t.compatibility),''),NULLIF(TRIM(r.compatibility),''),'') ELSE '' END"
);

/// Pending activities waiting for a retry, by task queue.
const RETRYING_SQL: &str = concat!(
    "SELECT COALESCE(a.queue,r.queue,'default') AS task_queue,COUNT(*) AS retrying_count,",
    "MIN(a.started_at) AS oldest_retrying_at",
    " FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id",
    " WHERE r.namespace=$1 AND a.status='pending' AND a.attempt_count>0",
    " GROUP BY COALESCE(a.queue,r.queue,'default')"
);

/// Failed activity attempts of retained runs, by task queue.
const FAILED_ATTEMPTS_SQL: &str = concat!(
    "SELECT COALESCE(a.queue,r.queue,'default') AS task_queue,COUNT(*) AS failed_count",
    " FROM activity_attempts t JOIN activity_executions a ON a.id=t.activity_execution_id",
    " JOIN workflow_runs r ON r.id=a.workflow_run_id",
    " WHERE r.namespace=$1 AND t.status='failed'",
    " GROUP BY COALESCE(a.queue,r.queue,'default')"
);

/// One queue, kind and build id's open tasks.
#[derive(Default)]
struct Backlog {
    ready: i64,
    due: i64,
    leased: i64,
    expired: i64,
    oldest_due: Option<DateTime<Utc>>,
}

impl Backlog {
    fn absorb(&mut self, other: Backlog) {
        self.ready += other.ready;
        self.due += other.due;
        self.leased += other.leased;
        self.expired += other.expired;
        self.oldest_due = match (self.oldest_due, other.oldest_due) {
            (Some(left), Some(right)) => Some(left.min(right)),
            (left, right) => left.or(right),
        };
    }
}

/// One queue's retrying activities, the oldest retrying start, and failed
/// attempts.
#[derive(Default)]
struct Retries {
    retrying: i64,
    oldest: Option<DateTime<Utc>>,
    failed: i64,
}

/// One queue's fresh worker registrations.
#[derive(Default)]
struct Workers {
    pollers: BTreeMap<String, i64>,
    slots: [i64; 2],
    available: [i64; 2],
}

/// Keeps the first [`BUILD_LIMIT`] build ids by name and sums the rest under
/// [`OTHER_BUILDS`].
fn bound_builds<T: Default>(builds: BTreeMap<String, T>, absorb: impl Fn(&mut T, T)) -> BTreeMap<String, T> {
    let mut bounded = BTreeMap::new();
    for (index, (build, value)) in builds.into_iter().enumerate() {
        if index < BUILD_LIMIT {
            bounded.insert(build, value);
        } else {
            absorb(bounded.entry(OTHER_BUILDS.to_owned()).or_default(), value);
        }
    }
    bounded
}

/// Seconds from `at` to `now`, never negative, to the millisecond.
fn age_seconds(at: DateTime<Utc>, now: DateTime<Utc>) -> f64 {
    (now - at).num_milliseconds().max(0) as f64 / 1000.0
}

/// A label value as the text format escapes it.
fn escape(value: &str) -> String {
    value.replace('\\', "\\\\").replace('"', "\\\"").replace('\n', "\\n")
}

/// A sample value: whole numbers without a fraction, and the text format's
/// spelling of infinities.
fn number(value: f64) -> String {
    if value == f64::INFINITY {
        "+Inf".into()
    } else if value.fract() == 0.0 && value.abs() < 9.0e15 {
        format!("{}", value as i64)
    } else {
        format!("{value}")
    }
}

/// The exposition text, one family at a time.
struct Exposition {
    text: String,
    namespace: String,
}

impl Exposition {
    fn new(namespace: &str) -> Self {
        Self { text: String::new(), namespace: namespace.to_owned() }
    }

    fn family(&mut self, name: &str, kind: &str, help: &str) {
        let _ = writeln!(self.text, "# HELP {name} {help}");
        let _ = writeln!(self.text, "# TYPE {name} {kind}");
    }

    /// One sample, labeled with the namespace first.
    fn sample(&mut self, name: &str, labels: &[(&str, &str)], value: f64) {
        let _ = write!(self.text, "{name}{{namespace=\"{}\"", escape(&self.namespace));
        for (label, value) in labels {
            let _ = write!(self.text, ",{label}=\"{}\"", escape(value));
        }
        let _ = writeln!(self.text, "}} {}", number(value));
    }
}

/// Backlog samples of one family, in queue, kind and build id order.
fn backlog_samples(out: &mut Exposition, name: &str, backlog: &BTreeMap<(String, String, String), Backlog>, value: impl Fn(&Backlog) -> f64) {
    for ((queue, kind, build), task) in backlog {
        out.sample(name, &[("task_queue", queue), ("task_kind", kind), ("build_id", build)], value(task));
    }
}

/// A run or activity series's labels, in the JSON series's order.
fn series_labels<'a>(series: &'a Value, dimensions: &[&'a str]) -> Vec<(&'a str, &'a str)> {
    dimensions
        .iter()
        .map(|dimension| (*dimension, series[*dimension].as_str().unwrap_or_default()))
        .collect()
}

/// The run or activity families from the JSON snapshot's series.
fn runtime_families(
    out: &mut Exposition,
    snapshot: &Snapshot,
    prefix: &str,
    noun: &str,
    dimensions: &[&str],
    statuses: &[&str],
) {
    let retained = format!("{prefix}_retained");
    out.family(&retained, "gauge", &format!("{noun} retained in visibility, by task queue and type. Retention pruning lowers it."));
    for series in &snapshot.series {
        let labels = series_labels(series, dimensions);
        out.sample(&retained, &labels, series["started_total"].as_f64().unwrap_or_default());
    }
    out.family(prefix, "gauge", &format!("{noun} retained in visibility, by task queue, type and status."));
    for series in &snapshot.series {
        for status in statuses {
            let field = if *status == "running" { "running".to_owned() } else { format!("{status}_total") };
            let mut labels = series_labels(series, dimensions);
            labels.push(("status", status));
            out.sample(prefix, &labels, series[field.as_str()].as_f64().unwrap_or_default());
        }
    }
    let histogram = format!("{prefix_singular}_duration_seconds", prefix_singular = prefix.trim_end_matches('s'));
    out.family(
        &histogram,
        "histogram",
        &format!("Durations of closed {} retained in visibility. Retention pruning lowers it, which Prometheus reads as a counter reset.", noun.to_lowercase()),
    );
    let bucket = format!("{histogram}_bucket");
    for series in &snapshot.series {
        let latency = &series["latency_seconds"];
        for (bound, _) in BUCKETS {
            let mut labels = series_labels(series, dimensions);
            labels.push(("le", bound));
            out.sample(&bucket, &labels, latency["buckets"][bound].as_f64().unwrap_or_default());
        }
        let count = latency["count"].as_f64().unwrap_or_default();
        let mut labels = series_labels(series, dimensions);
        labels.push(("le", "+Inf"));
        out.sample(&bucket, &labels, count);
        let labels = series_labels(series, dimensions);
        out.sample(&format!("{histogram}_sum"), &labels, latency["sum"].as_f64().unwrap_or_default());
        out.sample(&format!("{histogram}_count"), &labels, count);
    }
}

store_methods! {
    pub(crate) async fn prometheus_exposition(&self) -> Result<String> {
        let now = now();
        let mut out = Exposition::new(self.namespace.as_ref());
        let queues = self.exposition_queues(now).await?;
        let truncated_queues = queues.len() > SERIES_LIMIT;
        let observed_queues = queues.len();
        let queues: Vec<String> = queues.into_iter().take(SERIES_LIMIT).collect();
        let backlog = if queues.is_empty() { BTreeMap::new() } else { self.exposition_backlog(now, &queues).await? };
        let workers = self.exposition_workers(now, &queues).await?;
        let paused = self.exposition_paused().await?;

        out.family("dw_task_queue_ready_tasks", "gauge",
            "Ready tasks, due now or later, by task queue, task kind and build id.");
        backlog_samples(&mut out, "dw_task_queue_ready_tasks", &backlog, |task| task.ready as f64);
        out.family("dw_task_queue_due_tasks", "gauge",
            "Ready tasks whose availability time has passed: work waiting for a worker now. Visible while no worker polls.");
        backlog_samples(&mut out, "dw_task_queue_due_tasks", &backlog, |task| task.due as f64);
        out.family("dw_task_queue_delayed_tasks", "gauge",
            "Ready tasks scheduled for a later time (retry backoff, timers and delayed starts).");
        backlog_samples(&mut out, "dw_task_queue_delayed_tasks", &backlog, |task| (task.ready - task.due).max(0) as f64);
        out.family("dw_task_queue_leased_tasks", "gauge",
            "Tasks leased to a worker and not yet completed.");
        backlog_samples(&mut out, "dw_task_queue_leased_tasks", &backlog, |task| task.leased as f64);
        out.family("dw_task_queue_expired_leases", "gauge",
            "Leased tasks whose lease has expired and awaits repair.");
        backlog_samples(&mut out, "dw_task_queue_expired_leases", &backlog, |task| task.expired as f64);
        out.family("dw_task_queue_oldest_due_task_age_seconds", "gauge",
            "Seconds the oldest due task has been ready; 0 with no due task. Absent when due tasks have no recorded readiness time.");
        for ((queue, kind, build), task) in &backlog {
            let age = match (task.due, task.oldest_due) {
                (0, _) => Some(0.0),
                (_, Some(at)) => Some(age_seconds(at, now)),
                (_, None) => None,
            };
            if let Some(age) = age {
                out.sample("dw_task_queue_oldest_due_task_age_seconds",
                    &[("task_queue", queue), ("task_kind", kind), ("build_id", build)], age);
            }
        }

        let mut states = Exposition::new(self.namespace.as_ref());
        let mut remaining = Exposition::new(self.namespace.as_ref());
        for queue in &queues {
            for kind in KINDS {
                // Native enforces no server lease or dispatch budgets; a paused
                // activity dispatch (Server #577) admits nothing.
                let throttled = kind == "activity" && paused.contains(queue);
                let state = if throttled { "throttled" } else { "unlimited" };
                for candidate in ADMISSION_STATES {
                    states.sample("dw_task_queue_admission_state",
                        &[("task_queue", queue), ("task_kind", kind), ("state", candidate)],
                        if candidate == state { 1.0 } else { 0.0 });
                }
                if throttled {
                    remaining.sample("dw_task_queue_admission_remaining_capacity",
                        &[("task_queue", queue), ("task_kind", kind)], 0.0);
                }
            }
        }
        out.family("dw_task_queue_admission_state", "gauge",
            "Task dispatch admission for the queue and kind: 1 for the current state (accepting, throttled or unlimited), else 0. A throttled queue cannot use more workers.");
        out.text.push_str(&states.text);
        out.family("dw_task_queue_admission_remaining_capacity", "gauge",
            "Tasks the queue's admission budget still lets workers lease now. Absent when unlimited.");
        out.text.push_str(&remaining.text);

        out.family("dw_task_queue_dispatchable_tasks", "gauge",
            "Due tasks that admission lets a worker lease now: the due count, capped by remaining admission capacity. The scale-up signal.");
        for ((queue, kind, build), task) in &backlog {
            let throttled = *kind == "activity" && paused.contains(queue);
            out.sample("dw_task_queue_dispatchable_tasks",
                &[("task_queue", queue), ("task_kind", kind), ("build_id", build)],
                if throttled { 0.0 } else { task.due as f64 });
        }

        out.family("dw_task_queue_active_pollers", "gauge",
            "Workers registered on the queue with a fresh heartbeat, by build id. 0 when no worker runs.");
        for queue in &queues {
            match workers.get(queue) {
                Some(registered) if !registered.pollers.is_empty() => {
                    for (build, count) in &registered.pollers {
                        out.sample("dw_task_queue_active_pollers", &[("task_queue", queue), ("build_id", build)], *count as f64);
                    }
                }
                _ => out.sample("dw_task_queue_active_pollers", &[("task_queue", queue), ("build_id", "")], 0.0),
            }
        }
        out.family("dw_task_queue_worker_slots", "gauge",
            "Concurrent task slots the queue's fresh workers declare, by task kind.");
        for queue in &queues {
            let registered = workers.get(queue);
            for (index, kind) in KINDS.iter().enumerate() {
                out.sample("dw_task_queue_worker_slots", &[("task_queue", queue), ("task_kind", kind)],
                    registered.map_or(0, |workers| workers.slots[index]) as f64);
            }
        }
        out.family("dw_task_queue_available_worker_slots", "gauge",
            "Free task slots the queue's fresh workers last reported, by task kind.");
        for queue in &queues {
            let registered = workers.get(queue);
            for (index, kind) in KINDS.iter().enumerate() {
                out.sample("dw_task_queue_available_worker_slots", &[("task_queue", queue), ("task_kind", kind)],
                    registered.map_or(0, |workers| workers.available[index]) as f64);
            }
        }
        out.family("dw_task_queue_activity_dispatch_paused", "gauge",
            "1 while an operator pause holds new activity dispatch on the queue, else 0.");
        for queue in &queues {
            out.sample("dw_task_queue_activity_dispatch_paused", &[("task_queue", queue)],
                if paused.contains(queue) { 1.0 } else { 0.0 });
        }

        let (retries, truncated_retries, observed_retries) = self.exposition_retries().await?;
        out.family("dw_activity_retrying", "gauge",
            "Activities waiting for a retry attempt after a failed attempt, by task queue.");
        for (queue, retry) in &retries {
            out.sample("dw_activity_retrying", &[("task_queue", queue)], retry.retrying as f64);
        }
        out.family("dw_activity_oldest_retrying_age_seconds", "gauge",
            "Seconds since the oldest retrying activity first started; 0 with none retrying.");
        for (queue, retry) in &retries {
            if let Some(age) = match (retry.retrying, retry.oldest) {
                (0, _) => Some(0.0),
                (_, Some(at)) => Some(age_seconds(at, now)),
                (_, None) => None,
            } {
                out.sample("dw_activity_oldest_retrying_age_seconds", &[("task_queue", queue)], age);
            }
        }
        out.family("dw_activity_failed_attempts", "gauge",
            "Failed activity attempts of retained runs, by task queue. Retention pruning lowers it.");
        for (queue, retry) in &retries {
            out.sample("dw_activity_failed_attempts", &[("task_queue", queue)], retry.failed as f64);
        }

        self.schedule_families(&mut out, now).await?;

        let workflows = self.workflow_series().await?;
        let activities = self.activity_series().await?;
        runtime_families(&mut out, &workflows, "dw_workflow_runs", "Workflow runs",
            &["task_queue", "workflow_type"], &["running", "completed", "failed", "cancelled", "terminated"]);
        runtime_families(&mut out, &activities, "dw_activity_executions", "Activity executions",
            &["task_queue", "workflow_type", "activity_type"], &["running", "completed", "failed", "cancelled"]);

        let families = [
            ("task_queues", observed_queues, truncated_queues),
            ("activity_retries", observed_retries, truncated_retries),
            ("workflows", workflows.observed, workflows.lower_bound),
            ("activities", activities.observed, activities.lower_bound),
        ];
        out.family("dw_metrics_series_limit", "gauge", "The most label sets each bounded family reports.");
        for (family, ..) in families {
            out.sample("dw_metrics_series_limit", &[("family", family)], SERIES_LIMIT as f64);
        }
        out.family("dw_metrics_series_observed", "gauge",
            "Label sets each bounded family observed; a lower bound when truncated.");
        for (family, observed, _) in families {
            out.sample("dw_metrics_series_observed", &[("family", family)], observed as f64);
        }
        out.family("dw_metrics_series_truncated", "gauge", "1 when a bounded family left label sets out, else 0.");
        for (family, _, truncated) in families {
            out.sample("dw_metrics_series_truncated", &[("family", family)], if truncated { 1.0 } else { 0.0 });
        }
        Ok(out.text)
    }

    /// The namespace's queues with open tasks, fresh workers or a pause, by
    /// name: at most one more than the limit, to disclose truncation.
    async fn exposition_queues(&self, now: DateTime<Utc>) -> Result<Vec<String>> {
        let limit = i64::try_from(SERIES_LIMIT + 1).unwrap_or(i64::MAX);
        let stale_cutoff = super::whole_second(now - Duration::seconds(self.worker_stale_after_seconds));
        let mut queues = BTreeSet::new();
        for row in Self::query("SELECT DISTINCT COALESCE(queue,'default') AS task_queue FROM workflow_tasks WHERE namespace=$1 AND task_type IN ('workflow','activity') AND status IN ('ready','leased') ORDER BY task_queue LIMIT $2")
            .bind(self.namespace.as_ref())
            .bind(limit)
            .fetch_all(&self.pool)
            .await?
        {
            queues.insert(DB::string(&row, "task_queue")?);
        }
        for row in Self::query("SELECT DISTINCT COALESCE(task_queue,'default') AS task_queue FROM workflow_worker_registrations WHERE namespace=$1 AND status='active' AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at>=$2 ORDER BY task_queue LIMIT $3")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(stale_cutoff))
            .bind(limit)
            .fetch_all(&self.pool)
            .await?
        {
            queues.insert(DB::string(&row, "task_queue")?);
        }
        for row in Self::query("SELECT task_queue FROM dw_task_queue_pauses WHERE namespace=$1 AND resumed_at IS NULL ORDER BY task_queue LIMIT $2")
            .bind(self.namespace.as_ref())
            .bind(limit)
            .fetch_all(&self.pool)
            .await?
        {
            queues.insert(DB::string(&row, "task_queue")?);
        }
        Ok(queues.into_iter().take(SERIES_LIMIT + 1).collect())
    }

    /// Open tasks of the reported queues by queue, kind and bounded build id.
    async fn exposition_backlog(&self, now: DateTime<Utc>, queues: &[String]) -> Result<BTreeMap<(String, String, String), Backlog>> {
        // Placeholders are numbered in the order they appear, as MySQL binds them.
        let rows = Self::query(BACKLOG_SQL)
            .bind(DB::bind_time(now))
            .bind(DB::bind_time(now))
            .bind(DB::bind_time(now))
            .bind(self.namespace.as_ref())
            .fetch_all(&self.pool)
            .await?;
        let mut grouped: BTreeMap<(String, String), BTreeMap<String, Backlog>> = BTreeMap::new();
        for row in &rows {
            let queue = DB::string(row, "task_queue")?;
            if !queues.contains(&queue) {
                continue;
            }
            let kind = DB::string(row, "task_kind")?;
            let build = DB::optional_string(row, "build_id")?.unwrap_or_default();
            let task = Backlog {
                ready: DB::number(row, "ready_count")?,
                due: DB::number(row, "due_count")?,
                leased: DB::number(row, "leased_count")?,
                expired: DB::number(row, "expired_count")?,
                oldest_due: DB::optional_instant(row, "oldest_due_at")?,
            };
            grouped.entry((queue, kind)).or_default().entry(build).or_default().absorb(task);
        }
        let mut backlog = BTreeMap::new();
        for ((queue, kind), builds) in grouped {
            for (build, task) in bound_builds(builds, Backlog::absorb) {
                backlog.insert((queue.clone(), kind.clone(), build), task);
            }
        }
        Ok(backlog)
    }

    /// Fresh worker registrations of the reported queues. Capacity is summed
    /// here rather than in SQL, where MySQL's SUM is a decimal.
    async fn exposition_workers(&self, now: DateTime<Utc>, queues: &[String]) -> Result<BTreeMap<String, Workers>> {
        let stale_cutoff = super::whole_second(now - Duration::seconds(self.worker_stale_after_seconds));
        let rows = Self::query("SELECT COALESCE(task_queue,'default') AS task_queue,build_id,max_concurrent_workflow_tasks,max_concurrent_activity_tasks,available_workflow_slots,available_activity_slots FROM workflow_worker_registrations WHERE namespace=$1 AND status='active' AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at>=$2")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(stale_cutoff))
            .fetch_all(&self.pool)
            .await?;
        let mut workers: BTreeMap<String, Workers> = BTreeMap::new();
        for row in &rows {
            let queue = DB::string(row, "task_queue")?;
            if !queues.contains(&queue) {
                continue;
            }
            let build = DB::optional_string(row, "build_id")?.map(|build| build.trim().to_owned()).unwrap_or_default();
            let workflow = DB::optional_number(row, "max_concurrent_workflow_tasks")?;
            let activity = DB::optional_number(row, "max_concurrent_activity_tasks")?;
            let entry = workers.entry(queue).or_default();
            *entry.pollers.entry(build).or_default() += 1;
            entry.slots[0] += workflow.unwrap_or_default();
            entry.slots[1] += activity.unwrap_or_default();
            entry.available[0] += DB::optional_number(row, "available_workflow_slots")?.or(workflow).unwrap_or_default();
            entry.available[1] += DB::optional_number(row, "available_activity_slots")?.or(activity).unwrap_or_default();
        }
        for entry in workers.values_mut() {
            entry.pollers = bound_builds(std::mem::take(&mut entry.pollers), |total, count| *total += count);
        }
        Ok(workers)
    }

    /// Queues whose activity dispatch is paused.
    async fn exposition_paused(&self) -> Result<BTreeSet<String>> {
        let mut paused = BTreeSet::new();
        for row in Self::query("SELECT task_queue FROM dw_task_queue_pauses WHERE namespace=$1 AND resumed_at IS NULL")
            .bind(self.namespace.as_ref())
            .fetch_all(&self.pool)
            .await?
        {
            paused.insert(DB::string(&row, "task_queue")?);
        }
        Ok(paused)
    }

    /// Retrying activities, the oldest retrying start and failed attempts by
    /// task queue, for the first queues by name; whether any were left out;
    /// and how many queues were observed.
    async fn exposition_retries(&self) -> Result<(BTreeMap<String, Retries>, bool, usize)> {
        let mut retries: BTreeMap<String, Retries> = BTreeMap::new();
        for row in Self::query(RETRYING_SQL).bind(self.namespace.as_ref()).fetch_all(&self.pool).await? {
            let entry = retries.entry(DB::string(&row, "task_queue")?).or_default();
            entry.retrying += DB::number(&row, "retrying_count")?;
            entry.oldest = DB::optional_instant(&row, "oldest_retrying_at")?;
        }
        for row in Self::query(FAILED_ATTEMPTS_SQL).bind(self.namespace.as_ref()).fetch_all(&self.pool).await? {
            retries.entry(DB::string(&row, "task_queue")?).or_default().failed += DB::number(&row, "failed_count")?;
        }
        let observed = retries.len();
        let truncated = observed > SERIES_LIMIT;
        Ok((retries.into_iter().take(SERIES_LIMIT).collect(), truncated, observed))
    }

    /// Schedule state gauges for the namespace. Fire, failure and skip
    /// counters are not exported: the stored totals cover only existing
    /// schedules, so they fall when a schedule is deleted and are not
    /// counters.
    async fn schedule_families(&self, out: &mut Exposition, now: DateTime<Utc>) -> Result<()> {
        let mut states: BTreeMap<String, i64> = [("active".to_owned(), 0), ("paused".to_owned(), 0)].into();
        for row in Self::query("SELECT status,COUNT(*) AS n FROM workflow_schedules WHERE namespace=$1 AND status IN ('active','paused') GROUP BY status")
            .bind(self.namespace.as_ref())
            .fetch_all(&self.pool)
            .await?
        {
            states.insert(DB::string(&row, "status")?, DB::number(&row, "n")?);
        }
        let missed = Self::query("SELECT COUNT(*) AS n,MIN(next_fire_at) AS oldest_missed_at FROM workflow_schedules WHERE namespace=$1 AND status='active' AND next_fire_at IS NOT NULL AND next_fire_at<=$2")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(now))
            .fetch_one(&self.pool)
            .await?;
        let missed_count = DB::number(&missed, "n")?;
        let oldest = DB::optional_instant(&missed, "oldest_missed_at")?;
        out.family("dw_schedules", "gauge", "Schedules by state (active or paused).");
        for (state, count) in &states {
            out.sample("dw_schedules", &[("state", state)], *count as f64);
        }
        out.family("dw_schedules_missed", "gauge", "Active schedules whose next fire time has passed without firing.");
        out.sample("dw_schedules_missed", &[], missed_count as f64);
        out.family("dw_schedule_oldest_missed_age_seconds", "gauge",
            "Seconds since the most overdue active schedule's next fire time; 0 with none overdue.");
        out.sample("dw_schedule_oldest_missed_age_seconds", &[], match (missed_count, oldest) {
            (0, _) | (_, None) => 0.0,
            (_, Some(at)) => age_seconds(at, now),
        });
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn label_values_and_numbers_follow_the_text_format() {
        assert_eq!(escape("a\\b\"c\nd"), "a\\\\b\\\"c\\nd");
        assert_eq!(number(3.0), "3");
        assert_eq!(number(0.25), "0.25");
        assert_eq!(number(f64::INFINITY), "+Inf");
        let mut out = Exposition::new("team\"a");
        out.family("dw_x", "gauge", "Help.");
        out.sample("dw_x", &[("task_queue", "q\n1")], 2.0);
        out.sample("dw_x", &[], 0.5);
        assert_eq!(out.text, "# HELP dw_x Help.\n# TYPE dw_x gauge\ndw_x{namespace=\"team\\\"a\",task_queue=\"q\\n1\"} 2\ndw_x{namespace=\"team\\\"a\"} 0.5\n");
    }

    #[test]
    fn build_ids_beyond_the_limit_are_summed_under_one_label() {
        let builds: BTreeMap<String, i64> = (0..BUILD_LIMIT + 3).map(|index| (format!("b{index:02}"), 1)).collect();
        let bounded = bound_builds(builds, |total, count| *total += count);
        assert_eq!(bounded.len(), BUILD_LIMIT + 1);
        assert_eq!(bounded[OTHER_BUILDS], 3);
        assert_eq!(bounded.values().sum::<i64>(), (BUILD_LIMIT + 3) as i64);
    }

    #[test]
    fn backlog_absorbs_counts_and_keeps_the_oldest_time() {
        let early = DateTime::parse_from_rfc3339("2026-11-18T10:00:00Z").unwrap().to_utc();
        let late = early + Duration::seconds(30);
        let mut total = Backlog { ready: 1, due: 1, leased: 0, expired: 0, oldest_due: Some(late) };
        total.absorb(Backlog { ready: 2, due: 1, leased: 3, expired: 1, oldest_due: Some(early) });
        total.absorb(Backlog::default());
        assert_eq!((total.ready, total.due, total.leased, total.expired, total.oldest_due), (3, 2, 3, 1, Some(early)));
        assert_eq!(age_seconds(early, late), 30.0);
        assert_eq!(age_seconds(late, early), 0.0);
    }
}
