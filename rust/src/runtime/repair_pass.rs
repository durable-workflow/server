//! `POST /api/system/repair/pass`, as published Server 2.11 serves it
//! (`SystemController::repairPass` over `TaskWatchdog::runPass`): one
//! server-wide task repair pass, optionally scoped to runs, an instance, and
//! connections or queues.
//!
//! The pass recovers selected existing tasks (a ready task overdue for
//! dispatch is redispatched; an expired lease returns its task to ready),
//! creates a missing workflow task for a run that needs one, gives a run whose
//! deadline passed a task that closes it (or enforces an expired cancellation
//! cleanup deadline), and enforces expired activity deadlines. Selection is
//! PHP's poll-mode selection: up to 25 candidates of each kind, one from each
//! connection, queue and compatibility scope in turn, oldest first.
//!
//! An expired lease on a running activity is settled by its retry policy,
//! as `ActivityTimeoutEnforcer::enforceLeaseExpiry` settles it.
//!
//! A recovery native does not perform yet is reported as its candidate's
//! failure, so the pass answers 207 rather than claiming a repair: a missing
//! task for a run waiting on an activity, a timer, an update, a signal or a
//! child.
use super::Result;
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::operator_metrics::{REDISPATCH_AFTER_SECONDS, scope_rows, whole_second};
use super::repair_candidates::{ScopeRow, normalized};
use super::store::now;
use super::transaction::Transaction;
use chrono::{DateTime, Duration, Utc};
use serde_json::{Map, Value, json};
use sqlx::AssertSqlSafe;
use std::collections::VecDeque;
use std::time::Instant;

/// `TaskRepairPolicy::scanLimit()`.
const SCAN_LIMIT: usize = 25;
/// `TaskRepairPolicy::loopThrottleSeconds()`.
const LOOP_THROTTLE_SECONDS: u64 = 5;

/// The pass's request, after PHP's validation and trimming.
#[derive(Default)]
pub(crate) struct PassRequest {
    pub(crate) run_ids: Vec<String>,
    pub(crate) instance_id: Option<String>,
    pub(crate) connection: Option<String>,
    pub(crate) queue: Option<String>,
    pub(crate) respect_throttle: bool,
}

/// `repairPass`'s rules, in PHP's order. Laravel trims strings and reads
/// empty ones as null before validating.
pub(super) fn pass_request(body: &Value) -> Result<PassRequest> {
    let body = &super::start_request::normalized(body.clone());
    let mut errors = rule_errors(body, &[("run_ids", &[Rule::Nullable, Rule::Array, Rule::Max(100)])], |_, _| None);
    let items: Vec<(String, Value)> = match &body["run_ids"] {
        Value::Array(items) => items.iter().enumerate().map(|(index, item)| (index.to_string(), item.clone())).collect(),
        Value::Object(fields) => fields.iter().map(|(key, item)| (key.clone(), item.clone())).collect(),
        _ => Vec::new(),
    };
    for (key, item) in &items {
        let name = format!("run_ids.{key}");
        let mut value = Map::new();
        value.insert(name.clone(), item.clone());
        let rules: &[Rule] = &[Rule::String, Rule::Min(1), Rule::Max(128)];
        errors.extend(rule_errors(&Value::Object(value), &[(name.as_str(), rules)], |_, _| None));
    }
    errors.extend(rule_errors(
        body,
        &[
            ("instance_id", &[Rule::Nullable, Rule::String, Rule::Min(1), Rule::Max(128)]),
            ("connection", &[Rule::Nullable, Rule::String, Rule::Max(128)]),
            ("queue", &[Rule::Nullable, Rule::String, Rule::Max(128)]),
            ("respect_throttle", &[Rule::Nullable, Rule::Boolean]),
        ],
        |_, _| None,
    ));
    if let Some(error) = failure_messages(errors) {
        return Err(error);
    }
    let text = |field: &str| body[field].as_str().map(str::to_owned);
    Ok(PassRequest {
        run_ids: items.iter().filter_map(|(_, id)| id.as_str().map(str::to_owned)).collect(),
        instance_id: text("instance_id"),
        connection: text("connection"),
        queue: text("queue"),
        // `$request->boolean()`: true, 1 and "1" are true.
        respect_throttle: matches!(&body["respect_throttle"], Value::Bool(true))
            || body["respect_throttle"].as_i64() == Some(1)
            || body["respect_throttle"].as_str() == Some("1"),
    })
}

/// `emptyReport` with the controller's command-contract defaults.
fn empty_report(request: &PassRequest, throttled: bool) -> Map<String, Value> {
    let value = json!({
        "connection": request.connection, "queue": request.queue, "run_ids": request.run_ids,
        "instance_id": request.instance_id, "respect_throttle": request.respect_throttle,
        "selected_command_contract_candidates": 0, "backfilled_command_contracts": 0,
        "command_contract_backfill_unavailable": 0, "command_contract_failures": [],
        "existing_task_failures": [], "missing_run_failures": [], "throttled": throttled,
        "selected_existing_task_candidates": 0, "selected_missing_task_candidates": 0, "selected_total_candidates": 0,
        "repaired_existing_tasks": 0, "repaired_missing_tasks": 0, "dispatched_tasks": 0,
        "deadline_expired_candidates": 0, "deadline_expired_tasks_created": 0, "cancellation_deadlines_enforced": 0,
        "deadline_expired_failures": [], "activity_timeout_candidates": 0, "activity_timeouts_enforced": 0,
        "activity_timeout_failures": [], "service_call_timeout_candidates": 0, "service_call_timeouts_enforced": 0,
        "service_call_timeout_failures": [],
        // Published 2.11.3 (Core 2.14) also reports its service-call dispatch
        // outbox. Native runs a call's handler when it accepts the call, so it
        // never has a dispatch to enqueue.
        "service_call_dispatch_candidates": 0, "service_call_dispatches_enqueued": 0,
        "service_call_dispatch_failures": [],
    });
    match value {
        Value::Object(map) => map,
        _ => unreachable!("the report is an object"),
    }
}

/// Whether the report holds a failure that PHP answers with 207.
pub(super) fn failed(report: &Value) -> bool {
    ["existing_task_failures", "missing_run_failures", "command_contract_failures"]
        .iter()
        .any(|field| report[*field].as_array().is_some_and(|failures| !failures.is_empty()))
}

fn bump(report: &mut Map<String, Value>, field: &str) {
    let current = report[field].as_i64().unwrap_or_default();
    report.insert(field.to_owned(), json!(current + 1));
}

fn record_failure(report: &mut Map<String, Value>, field: &str, key: &str, id: &str, message: String) {
    if let Some(Value::Array(failures)) = report.get_mut(field) {
        failures.push(json!({key: id, "message": message}));
    }
}

/// `RequestedTaskScope::normalizeValues`: comma-separated, trimmed, unique.
fn scope_values(value: Option<&str>) -> Vec<String> {
    let mut values: Vec<String> = Vec::new();
    for candidate in value.unwrap_or_default().split(',').map(str::trim).filter(|value| !value.is_empty()) {
        if !values.iter().any(|value| value == candidate) {
            values.push(candidate.to_owned());
        }
    }
    values
}

enum Bind {
    Text(String),
    Time(DateTime<Utc>),
}

/// A statement built from fixed SQL text, with every value bound and numbered
/// for the backend.
pub(super) struct Sql {
    text: String,
    binds: Vec<Bind>,
    mysql: bool,
}

impl Sql {
    pub(super) fn new(text: &str, mysql: bool) -> Self {
        Self { text: text.to_owned(), binds: Vec::new(), mysql }
    }

    pub(super) fn push(&mut self, text: &str) -> &mut Self {
        self.text.push_str(text);
        self
    }

    fn bind(&mut self, value: Bind) -> &mut Self {
        self.binds.push(value);
        if self.mysql {
            self.text.push('?');
        } else {
            self.text.push_str(&format!("${}", self.binds.len()));
        }
        self
    }

    fn text(&mut self, value: &str) -> &mut Self {
        self.bind(Bind::Text(value.to_owned()))
    }

    fn time(&mut self, value: DateTime<Utc>) -> &mut Self {
        self.bind(Bind::Time(value))
    }

    fn list(&mut self, values: &[String]) -> &mut Self {
        self.push("(");
        for (index, value) in values.iter().enumerate() {
            if index > 0 {
                self.push(",");
            }
            self.text(value);
        }
        self.push(")")
    }

    /// `RequestedTaskScope::applyValue` for one column: `default` matches a
    /// null, empty or `default` value, and the default queue connection also
    /// matches the default.
    fn requested(&mut self, column: &str, value: Option<&str>) -> &mut Self {
        let mut values = scope_values(value);
        if values.is_empty() {
            return self;
        }
        let default_connection = std::env::var("QUEUE_CONNECTION").unwrap_or_else(|_| "database".to_owned());
        if column == "connection" && values.contains(&default_connection) && !values.iter().any(|value| value == "default") {
            values.push("default".to_owned());
        }
        let exact: Vec<String> = values.iter().filter(|value| *value != "default").cloned().collect();
        self.push(" AND (");
        if values.len() > exact.len() {
            self.push(column).push(" IS NULL OR ").push(column).push("='' OR ").push(column).push("='default'");
            if !exact.is_empty() {
                self.push(" OR ").push(column).push(" IN ").list(&exact);
            }
        } else {
            self.push(column).push(" IN ").list(&exact);
        }
        self.push(")")
    }

    /// `applyScope`: an absent scope value matches null, empty or the public
    /// default; a present one matches exactly.
    fn scope(&mut self, scope: &ScopeRow) -> &mut Self {
        let [connection, queue, compatibility] = scope.values();
        for (column, value, default) in [("connection", connection, "default"), ("queue", queue, "default"), ("compatibility", compatibility, "any")] {
            match value {
                None => {
                    self.push(" AND (").push(column).push(" IS NULL OR ").push(column).push("='' OR ").push(column);
                    self.push("='").push(default).push("')");
                }
                Some(value) => {
                    self.push(" AND ").push(column).push("=").text(value);
                }
            }
        }
        self
    }

    /// `existingTaskQuery`'s repairable tasks: a due ready task whose dispatch
    /// failed, whose claim failed, or whose dispatch and availability are
    /// overdue, and a leased task whose lease expired. "Now" binds in whole
    /// seconds, as Laravel binds a Carbon value; the redispatch cutoff binds
    /// with microseconds, as PHP formats it.
    fn repairable(&mut self, at: DateTime<Utc>) -> &mut Self {
        let bound = whole_second(at);
        let cutoff = at - Duration::seconds(REDISPATCH_AFTER_SECONDS);
        self.push("((status='ready' AND (available_at IS NULL OR available_at<=").time(bound).push(") AND ((");
        self.push("last_dispatch_attempt_at IS NOT NULL AND last_dispatch_error IS NOT NULL AND last_dispatch_error<>'' ");
        self.push("AND (last_dispatched_at IS NULL OR last_dispatch_attempt_at>last_dispatched_at) ");
        self.push("AND (repair_available_at IS NULL OR repair_available_at<=").time(bound).push("))");
        self.push(" OR (last_claim_failed_at IS NOT NULL AND last_claim_error IS NOT NULL AND last_claim_error<>'' ");
        self.push("AND ((repair_available_at IS NOT NULL AND repair_available_at<=").time(bound);
        self.push(") OR (repair_available_at IS NULL AND last_claim_failed_at<=").time(cutoff).push(")))");
        self.push(" OR ((available_at IS NULL OR available_at<=").time(cutoff).push(") ");
        self.push("AND (last_dispatch_attempt_at IS NULL OR last_dispatch_error IS NULL OR last_dispatch_error='' ");
        self.push("OR (last_dispatched_at IS NOT NULL AND last_dispatch_attempt_at<=last_dispatched_at)) ");
        self.push("AND (last_claim_failed_at IS NULL OR last_claim_error IS NULL OR last_claim_error='') ");
        self.push("AND (last_dispatched_at<=").time(cutoff).push(" OR (last_dispatched_at IS NULL AND created_at<=").time(cutoff);
        self.push("))))) OR (status='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=").time(bound).push("))")
    }

    /// `existingTaskQuery` with the pass's run, instance and scope filters.
    fn repairable_tasks(&mut self, request: &PassRequest, at: DateTime<Utc>) -> &mut Self {
        self.repairable(at);
        if !request.run_ids.is_empty() {
            self.push(" AND workflow_run_id IN ").list(&request.run_ids);
        }
        if let Some(instance) = &request.instance_id {
            self.push(" AND workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id=").text(instance).push(")");
        }
        self.requested("connection", request.connection.as_deref()).requested("queue", request.queue.as_deref())
    }

    /// `missingTaskRunQuery` with the pass's filters.
    fn missing_task_runs(&mut self, request: &PassRequest) -> &mut Self {
        self.push(super::repair_candidates::MISSING_TASK_RUNS);
        if !request.run_ids.is_empty() {
            self.push(" AND id IN ").list(&request.run_ids);
        }
        if let Some(instance) = &request.instance_id {
            self.push(" AND workflow_instance_id=").text(instance);
        }
        self.requested("connection", request.connection.as_deref()).requested("queue", request.queue.as_deref())
    }
}

/// `roundRobinCandidates`: one id from each scope in turn, up to the limit.
fn round_robin(mut buckets: Vec<VecDeque<String>>, limit: usize) -> Vec<String> {
    let mut selected = Vec::new();
    while selected.len() < limit {
        let mut progressed = false;
        for ids in buckets.iter_mut() {
            let Some(id) = ids.pop_front() else {
                continue;
            };
            selected.push(id);
            progressed = true;
            if selected.len() >= limit {
                break;
            }
        }
        if !progressed {
            break;
        }
    }
    selected
}

/// What one candidate's recovery did.
enum Recovered {
    /// A task is ready and was marked dispatched.
    Task,
    /// The candidate needed nothing, or a closed run's task was settled.
    Nothing,
    /// Native cannot perform this recovery yet; nothing was changed.
    Unported(String),
    /// A running activity lost its lease: its retry policy settles it.
    ExpireLease,
}

store_methods! {
    async fn rows(&self, sql: Sql) -> Result<Vec<DB::Row>> {
        let mut query = sqlx::query::<DB>(AssertSqlSafe(sql.text));
        for bind in sql.binds {
            query = match bind {
                Bind::Text(text) => query.bind(text),
                Bind::Time(time) => query.bind(DB::bind_time(time)),
            };
        }
        Ok(query.fetch_all(&self.pool).await?)
    }

    async fn ids(&self, sql: Sql) -> Result<Vec<String>> {
        self.rows(sql).await?.iter().map(|row| DB::string(row, "id")).collect()
    }

    /// `existingTaskScopeRows`.
    pub(super) async fn repairable_task_scopes(&self, request: &PassRequest, at: DateTime<Utc>) -> Result<Vec<ScopeRow>> {
        let mut sql = Sql::new("SELECT connection,queue,compatibility,COUNT(*) AS candidate_count,MIN(created_at) AS oldest_candidate_at FROM workflow_tasks WHERE ", DB::NAME == "MySQL");
        sql.repairable_tasks(request, at).push(" GROUP BY connection,queue,compatibility");
        scope_rows::<DB>(self.rows(sql).await?)
    }

    /// `missingTaskScopeRows`.
    pub(super) async fn missing_task_scopes(&self, request: &PassRequest) -> Result<Vec<ScopeRow>> {
        let mut sql = Sql::new("SELECT connection,queue,compatibility,COUNT(*) AS candidate_count,MIN(COALESCE(wait_started_at,started_at,created_at)) AS oldest_candidate_at FROM workflow_run_summaries WHERE ", DB::NAME == "MySQL");
        sql.missing_task_runs(request).push(" GROUP BY connection,queue,compatibility");
        scope_rows::<DB>(self.rows(sql).await?)
    }

    /// `TaskRepairCandidates::taskIds` in poll mode: each scope's oldest
    /// repairable tasks, by availability, creation and id, taken in turn.
    async fn repair_task_ids(&self, request: &PassRequest, at: DateTime<Utc>) -> Result<Vec<String>> {
        let scopes = self.repairable_task_scopes(request, at).await?;
        let mut buckets = Vec::new();
        for scope in normalized(&scopes) {
            let mut sql = Sql::new("SELECT id FROM workflow_tasks WHERE ", DB::NAME == "MySQL");
            sql.repairable_tasks(request, at).scope(scope);
            sql.push(&format!(" ORDER BY available_at,created_at,id LIMIT {SCAN_LIMIT}"));
            let ids = self.ids(sql).await?;
            if !ids.is_empty() {
                buckets.push(ids.into());
            }
        }
        Ok(round_robin(buckets, SCAN_LIMIT))
    }

    /// `TaskRepairCandidates::runIds`: each scope's runs that need a task, by
    /// wait, start and id, taken in turn.
    async fn repair_run_ids(&self, request: &PassRequest) -> Result<Vec<String>> {
        let scopes = self.missing_task_scopes(request).await?;
        let mut buckets = Vec::new();
        for scope in normalized(&scopes) {
            let mut sql = Sql::new("SELECT id FROM workflow_run_summaries WHERE ", DB::NAME == "MySQL");
            sql.missing_task_runs(request).scope(scope);
            sql.push(&format!(" ORDER BY wait_started_at,started_at,id LIMIT {SCAN_LIMIT}"));
            let ids = self.ids(sql).await?;
            if !ids.is_empty() {
                buckets.push(ids.into());
            }
        }
        Ok(round_robin(buckets, SCAN_LIMIT))
    }

    /// `deadlineExpiredRunIds`: open runs past their execution or run
    /// deadline without an open workflow task, and runs past their
    /// cancellation cleanup deadline.
    async fn deadline_expired_run_ids(&self, request: &PassRequest, at: DateTime<Utc>) -> Result<Vec<String>> {
        let mut sql = Sql::new("SELECT id FROM workflow_runs WHERE status IN ('pending','running','waiting') AND ((execution_deadline_at IS NOT NULL AND execution_deadline_at<=", DB::NAME == "MySQL");
        sql.time(at).push(") OR (run_deadline_at IS NOT NULL AND run_deadline_at<=").time(at);
        sql.push(") OR (cancellation_request_command_id IS NOT NULL AND cancellation_deadline_at IS NOT NULL AND cancellation_deadline_at<=").time(at);
        sql.push(")) AND (NOT EXISTS (SELECT 1 FROM workflow_tasks t WHERE t.workflow_run_id=workflow_runs.id AND t.task_type='workflow' AND t.status IN ('ready','leased'))");
        sql.push(" OR (cancellation_request_command_id IS NOT NULL AND cancellation_deadline_at IS NOT NULL AND cancellation_deadline_at<=").time(at).push("))");
        if !request.run_ids.is_empty() {
            sql.push(" AND id IN ").list(&request.run_ids);
        }
        if let Some(instance) = &request.instance_id {
            sql.push(" AND workflow_instance_id=").text(instance);
        }
        sql.requested("connection", request.connection.as_deref()).requested("queue", request.queue.as_deref());
        sql.push(&format!(" ORDER BY id LIMIT {SCAN_LIMIT}"));
        self.ids(sql).await
    }

    /// `ServiceCallDeadline::expiredCallIds`: admitted open calls past their
    /// deadline, earliest first, within the pass's runs and instance. A
    /// requested connection or queue matches the caller's or linked run
    /// exactly, as PHP's `whereHas` does; a call with neither run has no queue
    /// owner, so any scope may enforce it.
    async fn expired_service_call_ids(&self, request: &PassRequest, at: DateTime<Utc>) -> Result<Vec<String>> {
        let mut sql = Sql::new("SELECT c.id FROM workflow_service_calls c JOIN dw_service_call_deadlines d ON d.service_call_id=c.id WHERE c.status IN ('pending','accepted','started') AND c.accepted_at IS NOT NULL AND d.call_deadline_at<=", DB::NAME == "MySQL");
        sql.time(at);
        if !request.run_ids.is_empty() {
            sql.push(" AND (c.caller_workflow_run_id IN ").list(&request.run_ids);
            sql.push(" OR c.linked_workflow_run_id IN ").list(&request.run_ids).push(")");
        }
        if let Some(instance) = &request.instance_id {
            sql.push(" AND (c.caller_workflow_instance_id=").text(instance);
            sql.push(" OR c.linked_workflow_instance_id=").text(instance).push(")");
        }
        if request.connection.is_some() || request.queue.is_some() {
            sql.push(" AND (");
            for column in ["c.caller_workflow_run_id", "c.linked_workflow_run_id"] {
                sql.push("EXISTS (SELECT 1 FROM workflow_runs r WHERE r.id=").push(column);
                if let Some(connection) = &request.connection {
                    sql.push(" AND r.connection=").text(connection);
                }
                if let Some(queue) = &request.queue {
                    sql.push(" AND r.queue=").text(queue);
                }
                sql.push(") OR ");
            }
            sql.push("(c.caller_workflow_run_id IS NULL AND c.linked_workflow_run_id IS NULL))");
        }
        sql.push(&format!(" ORDER BY d.call_deadline_at,c.id LIMIT {SCAN_LIMIT}"));
        self.ids(sql).await
    }

    /// `ActivityTimeoutEnforcer::expiredExecutionIds` within the requested
    /// connections and queues.
    async fn scoped_expired_activity_ids(&self, request: &PassRequest, at: DateTime<Utc>) -> Result<Vec<String>> {
        let mut sql = Sql::new("SELECT id FROM activity_executions WHERE status IN ('pending','running') AND ((status='pending' AND schedule_deadline_at IS NOT NULL AND schedule_deadline_at<=", DB::NAME == "MySQL");
        sql.time(at).push(") OR (status='running' AND close_deadline_at IS NOT NULL AND close_deadline_at<=").time(at);
        sql.push(") OR (schedule_to_close_deadline_at IS NOT NULL AND schedule_to_close_deadline_at<=").time(at);
        sql.push(") OR (status='running' AND heartbeat_deadline_at IS NOT NULL AND heartbeat_deadline_at<=").time(at).push("))");
        sql.requested("connection", request.connection.as_deref()).requested("queue", request.queue.as_deref());
        sql.push(&format!(" ORDER BY id LIMIT {SCAN_LIMIT}"));
        self.ids(sql).await
    }

    /// `TaskWatchdog::runPass`, with its loop throttle shared by this
    /// server's namespaces.
    pub(crate) async fn repair_pass(&self, request: &PassRequest) -> Result<Value> {
        {
            let mut last = self.repair_pass_at.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
            let recent = last.is_some_and(|at| at.elapsed().as_secs() < LOOP_THROTTLE_SECONDS);
            if request.respect_throttle && recent {
                return Ok(Value::Object(empty_report(request, true)));
            }
            *last = Some(Instant::now());
        }
        let mut report = empty_report(request, false);
        let at = now();
        let existing = self.repair_task_ids(request, at).await?;
        let missing = self.repair_run_ids(request).await?;
        report.insert("selected_existing_task_candidates".into(), json!(existing.len()));
        report.insert("selected_missing_task_candidates".into(), json!(missing.len()));
        report.insert("selected_total_candidates".into(), json!(existing.len() + missing.len()));
        for task_id in &existing {
            let message = match self.recover_candidate_task(task_id).await {
                Ok(Recovered::Task) => {
                    bump(&mut report, "repaired_existing_tasks");
                    bump(&mut report, "dispatched_tasks");
                    continue;
                }
                Ok(Recovered::Nothing | Recovered::ExpireLease) => continue,
                Ok(Recovered::Unported(message)) => message,
                Err(error) => error.to_string(),
            };
            self.record_task_error(task_id, &message).await;
            record_failure(&mut report, "existing_task_failures", "candidate_id", task_id, message);
        }
        for run_id in &missing {
            let message = match self.recover_missing_run(run_id).await {
                Ok(Recovered::Task) => {
                    bump(&mut report, "repaired_missing_tasks");
                    bump(&mut report, "dispatched_tasks");
                    continue;
                }
                Ok(Recovered::Nothing | Recovered::ExpireLease) => continue,
                Ok(Recovered::Unported(message)) => message,
                Err(error) => error.to_string(),
            };
            record_failure(&mut report, "missing_run_failures", "run_id", run_id, message);
        }
        let deadlines = self.deadline_expired_run_ids(request, now()).await?;
        report.insert("deadline_expired_candidates".into(), json!(deadlines.len()));
        for run_id in &deadlines {
            match self.deadline_expired_task(run_id).await {
                Ok((created, enforced)) => {
                    if created {
                        bump(&mut report, "deadline_expired_tasks_created");
                        bump(&mut report, "dispatched_tasks");
                    }
                    if enforced {
                        bump(&mut report, "cancellation_deadlines_enforced");
                    }
                }
                Err(error) => record_failure(&mut report, "deadline_expired_failures", "run_id", run_id, error.to_string()),
            }
        }
        let calls = self.expired_service_call_ids(request, now()).await?;
        report.insert("service_call_timeout_candidates".into(), json!(calls.len()));
        for call_id in &calls {
            match self.expire_service_call(call_id).await {
                Ok(true) => bump(&mut report, "service_call_timeouts_enforced"),
                Ok(false) => {}
                Err(error) => record_failure(&mut report, "service_call_timeout_failures", "call_id", call_id, error.to_string()),
            }
        }
        let activities = self.scoped_expired_activity_ids(request, now()).await?;
        report.insert("activity_timeout_candidates".into(), json!(activities.len()));
        for activity_id in &activities {
            match self.enforce_activity_deadline(activity_id).await {
                Ok(super::activity_deadlines::Enforcement::Enforced { .. }) => {
                    // Native always opens the next task: the retry, or the
                    // workflow task that sees the timeout.
                    bump(&mut report, "activity_timeouts_enforced");
                    bump(&mut report, "dispatched_tasks");
                }
                Ok(super::activity_deadlines::Enforcement::Skipped(reason)) => {
                    record_failure(&mut report, "activity_timeout_failures", "execution_id", activity_id, reason.to_owned());
                }
                Err(error) => record_failure(&mut report, "activity_timeout_failures", "execution_id", activity_id, error.to_string()),
            }
        }
        self.wake.notify_waiters();
        Ok(Value::Object(report))
    }

    /// `failedExistingTask`: the failure is kept on the task for operators.
    /// Recording it must not stop the pass.
    async fn record_task_error(&self, task_id: &str, message: &str) {
        let _ = Self::query("UPDATE workflow_tasks SET last_error=$1 WHERE id=$2")
            .bind(message)
            .bind(task_id)
            .execute(&self.pool)
            .await;
    }

    /// `recoverExistingTask` for one candidate, under its run's lock.
    async fn recover_candidate_task(&self, task_id: &str) -> Result<Recovered> {
        let mut tx = self.begin().await?;
        let Some(snapshot) = Self::query("SELECT workflow_run_id FROM workflow_tasks WHERE id=$1").bind(task_id).fetch_optional(&mut *tx).await? else {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        };
        let run_id = DB::string(&snapshot, "workflow_run_id")?;
        let Some(run) = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&run_id).fetch_optional(&mut *tx).await? else {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        };
        let Some(task) = Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND workflow_run_id=$2")
            .bind(task_id)
            .bind(&run_id)
            .fetch_optional(&mut *tx)
            .await?
        else {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        };
        let at = now();
        if !super::task_repair::needs_recovery::<DB>(&task, at)? {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        }
        let recovered = Self::recover_task_row(&mut tx, &run, &task, at).await?;
        match recovered {
            Recovered::Unported(_) => return Ok(recovered),
            Recovered::ExpireLease => {
                drop(tx);
                return self.settle_lease(task_id).await;
            }
            _ => {}
        }
        Self::project_run_summary(&mut tx, &run_id).await?;
        if let Recovered::Task = recovered {
            Self::mark_dispatched(&mut tx, task_id).await?;
        }
        tx.commit().await?;
        Ok(recovered)
    }

    /// `TaskRepair::recoverExistingTask` for a task that needs recovery.
    async fn recover_task_row(tx: &mut Transaction<'_, DB>, run: &DB::Row, task: &DB::Row, at: DateTime<Utc>) -> Result<Recovered> {
        let task_id = DB::string(task, "id")?;
        let task_type = DB::string(task, "task_type")?;
        let run_status = DB::string(run, "status")?;
        let closed = matches!(run_status.as_str(), "completed" | "failed" | "cancelled" | "terminated");
        let execution = if task_type == "activity" {
            match DB::document_row(task, "payload")?["activity_execution_id"].as_str() {
                Some(execution_id) => Self::query("SELECT * FROM activity_executions WHERE id=$1")
                    .bind(execution_id.to_owned())
                    .fetch_optional(&mut **tx)
                    .await?,
                None => None,
            }
        } else {
            None
        };
        let execution_status = execution.as_ref().map(|row| DB::string(row, "status")).transpose()?;
        // Native leaves an activity open after its run closes only when the
        // run completed without waiting for it (`ActivityAbandonment::allows`).
        let abandoned = closed && matches!(execution_status.as_deref(), Some("pending" | "running"));
        if closed && !abandoned {
            // `settleTerminalTask`, closing a cancelled or terminated run's
            // running attempt.
            if matches!(run_status.as_str(), "cancelled" | "terminated")
                && let Some(execution) = &execution
            {
                Self::close_current_attempt(tx, execution, "cancelled", at).await?;
            }
            let status = if DB::string(task, "status")? == "cancelled" {
                "cancelled"
            } else if run_status == "failed" {
                "failed"
            } else {
                "completed"
            };
            Self::query("UPDATE workflow_tasks SET status=$1,leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,updated_at=$2 WHERE id=$3")
                .bind(status).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
            return Ok(Recovered::Nothing);
        }
        if abandoned
            && let Some(execution) = &execution
            && super::activity_deadlines::expired_kind::<DB>(execution, at)?.is_some()
        {
            // Timeout enforcement owns the abandoned activity's outcome.
            return Ok(Recovered::Nothing);
        }
        let repair_count = || {
            DB::number(task, "repair_count")?
                .checked_add(1)
                .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
                .ok_or_else(|| super::refuse(axum::http::StatusCode::CONFLICT, "repair_count_exhausted"))
        };
        match DB::string(task, "status")?.as_str() {
            "ready" => {
                Self::query("UPDATE workflow_tasks SET repair_count=$1,repair_available_at=NULL,last_error=NULL,updated_at=$2 WHERE id=$3")
                    .bind(repair_count()?).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
            }
            "leased" => {
                // Loss of a running activity's lease consumes its retry
                // policy; it never redispatches the same attempt.
                if execution_status.as_deref() == Some("running") {
                    return Ok(Recovered::ExpireLease);
                }
                let repair_count = repair_count()?;
                // `closeExpiredActivityAttempt`.
                if let Some(execution) = &execution {
                    Self::close_current_attempt(tx, execution, "expired", at).await?;
                }
                Self::query("UPDATE workflow_tasks SET status='ready',leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$1,repair_available_at=NULL,last_error=NULL,updated_at=$2 WHERE id=$3")
                    .bind(repair_count).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
            }
            "failed" if super::task_repair::replay_blocked::<DB>(task)? => {
                // `recoverFailedReplayTask`.
                let repair_count = repair_count()?;
                let mut payload = DB::document_row(task, "payload")?;
                if let Some(fields) = payload.as_object_mut() {
                    fields.retain(|key, _| !key.starts_with("replay_blocked"));
                }
                Self::query("UPDATE workflow_tasks SET status='ready',payload=$1,leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$2,repair_available_at=NULL,last_error=NULL,updated_at=$3 WHERE id=$4")
                    .bind(DB::document(&payload)).bind(repair_count).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
            }
            _ => return Ok(Recovered::Nothing),
        }
        tx.summary_changed(&DB::string(run, "id")?);
        Ok(Recovered::Task)
    }

    /// `ActivityTimeoutEnforcer::enforceLeaseExpiry` for a repair candidate:
    /// a settled lease opens the retry or the workflow task that sees the
    /// failure. A lease the enforcer leaves alone needs no repair.
    async fn settle_lease(&self, task_id: &str) -> Result<Recovered> {
        Ok(match self.enforce_lease_expiry(task_id).await? {
            super::activity_deadlines::Enforcement::Enforced { .. } => Recovered::Task,
            super::activity_deadlines::Enforcement::Skipped(_) => Recovered::Nothing,
        })
    }

    /// `closeActivityAttempt` for an execution's current running attempt.
    async fn close_current_attempt(tx: &mut Transaction<'_, DB>, execution: &DB::Row, status: &str, at: DateTime<Utc>) -> Result<()> {
        if let Some(attempt) = DB::optional_string(execution, "current_attempt_id")? {
            Self::query("UPDATE activity_attempts SET status=$1,lease_expires_at=NULL,closed_at=COALESCE(closed_at,$2),updated_at=$3 WHERE id=$4 AND status='running'")
                .bind(status).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(attempt).execute(&mut **tx).await?;
        }
        Ok(())
    }

    /// `TaskDispatcher::markDispatched` in poll mode.
    async fn mark_dispatched(tx: &mut Transaction<'_, DB>, task_id: &str) -> Result<()> {
        let at = now();
        Self::query("UPDATE workflow_tasks SET last_dispatch_attempt_at=$1,last_dispatched_at=$2,last_dispatch_error=NULL,repair_available_at=NULL WHERE id=$3 AND status='ready'")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(task_id).execute(&mut **tx).await?;
        Ok(())
    }

    /// `recoverMissingTask`: a run whose projected summary still needs repair
    /// and names no next task gets its recoverable task back, or a new one.
    async fn recover_missing_run(&self, run_id: &str) -> Result<Recovered> {
        let mut tx = self.begin().await?;
        let Some(run) = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(run_id).fetch_optional(&mut *tx).await? else {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        };
        Self::project_run_summary(&mut tx, run_id).await?;
        let Some(summary) = Self::query("SELECT liveness_state,next_task_id,wait_kind,resume_source_kind FROM workflow_run_summaries WHERE id=$1")
            .bind(run_id)
            .fetch_optional(&mut *tx)
            .await?
        else {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        };
        if DB::optional_string(&summary, "liveness_state")?.as_deref() != Some("repair_needed")
            || DB::optional_string(&summary, "next_task_id")?.is_some()
        {
            tx.commit().await?;
            return Ok(Recovered::Nothing);
        }
        let at = now();
        // `recoverableTask`: a replay-blocked task first, then the earliest.
        let mut recoverable = Vec::new();
        for task in Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased','failed')")
            .bind(run_id)
            .fetch_all(&mut *tx)
            .await?
        {
            let blocked = super::task_repair::replay_blocked::<DB>(&task)?;
            if blocked || super::task_repair::needs_recovery::<DB>(&task, at)? {
                recoverable.push(((!blocked, super::task_repair::recovery_order::<DB>(&task)?), task));
            }
        }
        recoverable.sort_by(|left, right| left.0.cmp(&right.0));
        let (recovered, task_id) = match recoverable.into_iter().next() {
            Some((_, task)) => {
                let task_id = DB::string(&task, "id")?;
                (Self::recover_task_row(&mut tx, &run, &task, at).await?, task_id)
            }
            None => {
                let wait_kind = DB::optional_string(&summary, "wait_kind")?.unwrap_or_default();
                let timer_resume = DB::optional_string(&summary, "resume_source_kind")?.as_deref() == Some("timer");
                if matches!(wait_kind.as_str(), "activity" | "timer" | "update" | "signal" | "child")
                    || (wait_kind == "condition" && timer_resume)
                {
                    return Ok(Recovered::Unported(format!(
                        "Native does not yet create a missing task for a run waiting on {wait_kind}."
                    )));
                }
                // `createMissingTask`'s workflow task, with
                // `forMissingWorkflowTask`'s empty payload.
                let queue = DB::string(&run, "queue")?;
                let task_id = Self::create_task_dispatched(&mut tx, run_id, &queue, "workflow", json!({}), false).await?;
                Self::query("UPDATE workflow_tasks SET repair_count=1 WHERE id=$1").bind(&task_id).execute(&mut *tx).await?;
                (Recovered::Task, task_id)
            }
        };
        match recovered {
            Recovered::Unported(_) => return Ok(recovered),
            Recovered::ExpireLease => {
                drop(tx);
                return self.settle_lease(&task_id).await;
            }
            _ => {}
        }
        Self::project_run_summary(&mut tx, run_id).await?;
        if let Recovered::Task = recovered {
            Self::mark_dispatched(&mut tx, &task_id).await?;
        }
        tx.commit().await?;
        Ok(recovered)
    }

    /// `createDeadlineExpiredTask`: an expired cancellation cleanup deadline
    /// is enforced under a control task; another expired deadline gets a
    /// workflow task, and native closes the run when that task runs. Returns
    /// whether a task was created and whether a cleanup deadline was enforced.
    async fn deadline_expired_task(&self, run_id: &str) -> Result<(bool, bool)> {
        let mut tx = self.begin().await?;
        let Some(run) = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(run_id).fetch_optional(&mut *tx).await? else {
            tx.commit().await?;
            return Ok((false, false));
        };
        if !matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting") {
            tx.commit().await?;
            return Ok((false, false));
        }
        let open = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased') ORDER BY id LIMIT 1")
            .bind(run_id)
            .fetch_optional(&mut *tx)
            .await?;
        let at = now();
        let expired = |field: &str| -> Result<bool> { Ok(DB::optional_instant(&run, field)?.is_some_and(|deadline| at >= deadline)) };
        let cleanup = DB::optional_string(&run, "cancellation_request_command_id")?.is_some() && expired("cancellation_deadline_at")?;
        if !(expired("execution_deadline_at")? || expired("run_deadline_at")? || cleanup) {
            tx.commit().await?;
            return Ok((false, false));
        }
        let queue = DB::string(&run, "queue")?;
        if cleanup {
            // Expiry is runtime authority; an absent worker must not keep the
            // run open.
            let control = match open {
                Some(task) => task,
                None => {
                    let payload = json!({"reason": "cleanup_deadline_expired"});
                    let task_id = Self::create_task_dispatched(&mut tx, run_id, &queue, "workflow", payload, false).await?;
                    Self::query("UPDATE workflow_tasks SET repair_count=1 WHERE id=$1").bind(&task_id).execute(&mut *tx).await?;
                    Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(&task_id).fetch_one(&mut *tx).await?
                }
            };
            Self::finish_root_cancellation(&mut tx, &run, Some(&control)).await?;
            tx.summary_changed(run_id);
            tx.commit().await?;
            return Ok((false, true));
        }
        if open.is_some() {
            tx.commit().await?;
            return Ok((false, false));
        }
        let task_id = Self::create_task_dispatched(&mut tx, run_id, &queue, "workflow", json!({"reason": "deadline_expired"}), false).await?;
        Self::query("UPDATE workflow_tasks SET repair_count=1 WHERE id=$1").bind(&task_id).execute(&mut *tx).await?;
        Self::project_run_summary(&mut tx, run_id).await?;
        Self::mark_dispatched(&mut tx, &task_id).await?;
        tx.commit().await?;
        Ok((true, false))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_robin_takes_one_from_each_scope_in_turn() {
        let bucket = |ids: &[&str]| ids.iter().map(|id| (*id).to_owned()).collect::<VecDeque<_>>();
        let buckets = vec![bucket(&["a1", "a2", "a3"]), bucket(&["b1"]), bucket(&["c1", "c2"])];
        assert_eq!(round_robin(buckets, 5), ["a1", "b1", "c1", "a2", "c2"]);
    }

    #[test]
    fn requested_scopes_match_php() {
        let mut sql = Sql::new("", false);
        sql.requested("queue", Some(" a , default, a ,b"));
        assert_eq!(sql.text, " AND (queue IS NULL OR queue='' OR queue='default' OR queue IN ($1,$2))");
        let mut sql = Sql::new("", true);
        sql.requested("connection", Some("redis"));
        assert_eq!(sql.text, " AND (connection IN (?))");
        let mut sql = Sql::new("", false);
        sql.requested("connection", Some(" , "));
        assert_eq!(sql.text, "");
    }

    #[test]
    fn request_rules_and_trimming() {
        let request = pass_request(&json!({"run_ids": [" r "], "queue": "", "respect_throttle": "1"})).unwrap();
        assert_eq!((request.run_ids, request.queue, request.respect_throttle), (vec!["r".to_owned()], None, true));
        assert!(pass_request(&json!({"run_ids": "x"})).is_err());
        assert!(pass_request(&json!({"run_ids": [""]})).is_err());
        assert!(pass_request(&json!({"respect_throttle": "yes"})).is_err());
        assert!(pass_request(&Value::Null).is_ok());
    }
}
