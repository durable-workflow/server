//! `GET|POST /api/system/metrics`, as published Server 2.11 serves it
//! (`SystemController::metrics`): the namespace's workflow task failures,
//! the server's projection drift, and the namespace's request admission,
//! durable-state and external payload usage, each with PHP's bounded label
//! policy.
//!
//! Native enforces no namespace request admission, durable-state quota or
//! external payload quota, and the configuration audit refuses settings that
//! would configure one. Their limits are therefore null and their rejection
//! counters zero, as PHP reports for an unconfigured server; usage is
//! measured.
use super::Result;
use super::store::now;
use serde_json::{Map, Value, json};

/// PHP's default `server.metrics.workflow_task_failure_type_limit`.
const WORKFLOW_TYPE_LIMIT: i64 = 20;

const PROJECTION_TABLES: [&str; 5] =
    ["run_summaries", "run_waits", "run_timeline_entries", "run_timer_entries", "run_lineage_entries"];

const ADMISSION_REASONS: [&str; 3] = [
    "namespace_request_rate_exhausted",
    "namespace_request_concurrency_exhausted",
    "namespace_request_admission_unavailable",
];

const PAYLOAD_REASONS: [&str; 3] = [
    "external_payload_namespace_bytes_exhausted",
    "external_payload_namespace_objects_exhausted",
    "external_payload_namespace_quota_unavailable",
];

/// `NamespaceDurableStateQuota::usage`, resource by resource, in PHP's order.
const DURABLE_STATE: [(&str, &str); 16] = [
    ("workflow_instances", "SELECT COUNT(*) FROM workflow_instances WHERE namespace=$1"),
    ("workflow_runs", "SELECT COUNT(*) FROM workflow_runs WHERE namespace=$1"),
    ("open_workflow_runs", "SELECT COUNT(*) FROM workflow_runs WHERE namespace=$1 AND status IN ('pending','running','waiting')"),
    ("schedules", "SELECT COUNT(*) FROM workflow_schedules WHERE namespace=$1"),
    ("schedule_history_events", "SELECT COUNT(*) FROM workflow_schedule_history_events WHERE namespace=$1"),
    ("worker_registrations", "SELECT COUNT(*) FROM workflow_worker_registrations WHERE namespace=$1"),
    ("workflow_history_events", "SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1)"),
    ("workflow_tasks", "SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1"),
    ("pending_workflow_tasks", "SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND status='ready'"),
    ("workflow_timers", "SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1)"),
    ("pending_workflow_timers", "SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1) AND status='pending'"),
    ("workflow_run_waits", "SELECT COUNT(*) FROM workflow_run_waits WHERE workflow_instance_id IN (SELECT id FROM workflow_instances WHERE namespace=$1)"),
    ("open_workflow_run_waits", "SELECT COUNT(*) FROM workflow_run_waits WHERE workflow_instance_id IN (SELECT id FROM workflow_instances WHERE namespace=$1) AND status='open'"),
    ("workflow_commands", "SELECT COUNT(*) FROM workflow_commands WHERE workflow_instance_id IN (SELECT id FROM workflow_instances WHERE namespace=$1)"),
    ("workflow_streams", "SELECT (SELECT COUNT(*) FROM workflow_durable_streams WHERE namespace=$1)+(SELECT COUNT(*) FROM workflow_inbound_streams WHERE namespace=$2)"),
    ("workflow_stream_items", "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE namespace=$1)+(SELECT COUNT(*) FROM workflow_inbound_stream_items WHERE namespace=$2)"),
];

/// The namespace's failed workflow tasks, joined to their runs.
macro_rules! failed_tasks {
    () => {
        "FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.task_type='workflow' AND t.status='failed' AND t.attempt_count>0"
    };
}

fn zeros(reasons: &[&str]) -> Value {
    Value::Object(reasons.iter().map(|reason| ((*reason).to_owned(), json!(0))).collect())
}

fn nulls(keys: &[&str]) -> Value {
    Value::Object(keys.iter().map(|key| ((*key).to_owned(), Value::Null)).collect())
}

store_methods! {
    async fn namespace_count(&self, sql: &'static str, binds: usize) -> Result<i64> {
        let mut query = Self::scalar(sql);
        for _ in 0..binds {
            query = query.bind(self.namespace.as_ref());
        }
        Ok(query.fetch_one(&self.pool).await?)
    }

    pub(crate) async fn system_metrics(&self) -> Result<Value> {
        let failures = self.workflow_task_failures().await?;
        let drift = self.projection_drift().await?;
        let admission = json!({
            "max_requests_per_minute": null, "max_concurrent_requests": null,
            "requests_this_minute": 0, "rejections_this_minute": 0,
            "rejections_by_reason": zeros(&ADMISSION_REASONS), "configuration_status": "valid",
            "label_cardinality_policy": {"namespace": "request_scope_not_label", "reason": "finite_three_reason_inventory"},
        });
        let durable = self.durable_state_usage().await?;
        let payloads = json!({
            "max_bytes": null, "max_objects": null,
            "used_bytes": self.namespace_count(DB::EXTERNAL_PAYLOAD_BYTES, 1).await?.max(0),
            "used_objects": self.namespace_count("SELECT COUNT(*) FROM runtime_external_payloads WHERE namespace=$1", 1).await?,
            "remaining_bytes": null, "remaining_objects": null,
            "rejections_this_minute": 0, "rejections_by_reason": zeros(&PAYLOAD_REASONS),
            "configuration_status": "valid", "measurement_status": "available",
            "label_cardinality_policy": {"namespace": "request_scope_not_label", "reason": "finite_three_reason_inventory"},
        });
        let families = [
            ("dw_workflow_task_consecutive_failures", failures),
            ("dw_projection_drift_total", drift),
            ("dw_namespace_request_admission_rejections", admission),
            ("dw_namespace_durable_state_usage", durable),
            ("dw_runtime_external_payload_namespace_usage", payloads),
        ];
        let label_sets: Map<String, Value> = families
            .iter()
            .map(|(name, family)| ((*name).to_owned(), family["label_cardinality_policy"].clone()))
            .collect();
        let metrics: Map<String, Value> = families.into_iter().map(|(name, family)| (name.to_owned(), family)).collect();
        Ok(json!({
            "generated_at": now().to_rfc3339_opts(chrono::SecondsFormat::Micros, true),
            "namespace": self.namespace.as_ref(),
            "metrics": metrics,
            "cardinality": {"metric_label_sets": label_sets},
        }))
    }

    /// `WorkflowTaskFailureMetrics::snapshot`: failed workflow tasks, the
    /// first failure counting as one.
    async fn workflow_task_failures(&self) -> Result<Value> {
        // PostgreSQL's MAX keeps the integer column's width, so decode it by
        // row. No COALESCE: MariaDB turns COALESCE(MAX(unsigned),0) into DECIMAL.
        const MAX: &str = concat!("SELECT MAX(t.attempt_count) AS n ", failed_tasks!());
        const COUNT: &str = concat!("SELECT COUNT(t.id) ", failed_tasks!());
        const TYPES: &str = concat!("SELECT COUNT(DISTINCT r.workflow_type) ", failed_tasks!());
        const ROWS: &str = concat!(
            "SELECT r.workflow_type AS workflow_type,MAX(t.attempt_count) AS max_consecutive_failures,COUNT(*) AS failed_task_count ",
            failed_tasks!(),
            " GROUP BY r.workflow_type ORDER BY max_consecutive_failures DESC,r.workflow_type LIMIT 20"
        );
        let max = DB::optional_number(&Self::query(MAX).bind(self.namespace.as_ref()).fetch_one(&self.pool).await?, "n")?
            .unwrap_or(0);
        let failed = self.namespace_count(COUNT, 1).await?;
        let types = self.namespace_count(TYPES, 1).await?;
        let rows = Self::query(ROWS).bind(self.namespace.as_ref()).fetch_all(&self.pool).await?;
        let mut series = Vec::new();
        let mut reported = 0;
        for row in &rows {
            let tasks = DB::number(row, "failed_task_count")?;
            reported += tasks;
            series.push(json!({"workflow_type": DB::string(row, "workflow_type")?,
                "max_consecutive_failures": DB::number(row, "max_consecutive_failures")?, "failed_task_count": tasks}));
        }
        let shown = series.len() as i64;
        Ok(json!({
            "max_consecutive_failures": max, "failed_task_count": failed, "workflow_type_count": types,
            "workflow_type_limit": WORKFLOW_TYPE_LIMIT, "workflow_types_truncated": types > shown,
            "suppressed_workflow_type_count": (types - shown).max(0), "suppressed_failed_task_count": (failed - reported).max(0),
            "label_cardinality_policy": {"namespace": "request_scope_not_label", "workflow_type": {
                "cardinality_class": "bounded_series", "limit": WORKFLOW_TYPE_LIMIT,
                "selection": "top_by_max_consecutive_failures_then_name"}},
            "by_workflow_type": series,
        }))
    }

    /// `ProjectionDriftMetrics::snapshot`, server-wide. Native maintains
    /// run waits with history and derives the timeline, timer and lineage
    /// views from it, so only a run without its summary needs a rebuild.
    async fn projection_drift(&self) -> Result<Value> {
        let missing = Self::scalar("SELECT COUNT(*) FROM workflow_runs r WHERE NOT EXISTS (SELECT 1 FROM workflow_run_summaries s WHERE s.id=r.id)")
            .fetch_one(&self.pool)
            .await?;
        let by_table: Vec<Value> = PROJECTION_TABLES
            .iter()
            .map(|table| json!({"table": table, "needs_rebuild": if *table == "run_summaries" { missing } else { 0 }}))
            .collect();
        Ok(json!({
            "total": missing, "table_count": PROJECTION_TABLES.len(), "tables_with_drift": i64::from(missing > 0), "scope": "server",
            "label_cardinality_policy": {"namespace": "server_scope_no_label", "table": {
                "cardinality_class": "finite_projection_table_inventory", "values": PROJECTION_TABLES,
                "selection": "fixed_projection_table_inventory"}},
            "by_table": by_table,
        }))
    }

    /// `NamespaceDurableStateQuota::metrics` with no configured limits.
    async fn durable_state_usage(&self) -> Result<Value> {
        let mut usage = Map::new();
        for (resource, sql) in DURABLE_STATE {
            let binds = if sql.contains("$2") { 2 } else { 1 };
            usage.insert(resource.to_owned(), json!(self.namespace_count(sql, binds).await?));
        }
        let resources: Vec<&str> = DURABLE_STATE.iter().map(|(resource, _)| *resource).collect();
        let mut reasons: Vec<String> = resources.iter().map(|resource| format!("namespace_{resource}_exhausted")).collect();
        reasons.push("namespace_durable_state_quota_unavailable".into());
        let reasons: Vec<&str> = reasons.iter().map(String::as_str).collect();
        Ok(json!({
            "limits": nulls(&resources), "usage": usage, "remaining": nulls(&resources),
            "rejections_this_minute": 0, "rejections_by_reason": zeros(&reasons),
            "configuration_status": "valid", "measurement_status": "available",
            "label_cardinality_policy": {"namespace": "request_scope_not_label", "reason": "finite_reason_inventory"},
        }))
    }
}
