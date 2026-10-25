//! `GET /api/task-queues/{name}/build-ids` as published PHP 2.10.0's
//! `TaskQueueController::buildIds` answers it, through
//! `TaskQueueBuildIdRolloutSnapshot::forTaskQueue`: the queue's worker
//! registrations grouped by trimmed build id, with each build id's pending
//! workflow tasks.
//!
//! Each build id carries its rollout row's drain intent and times, and
//! whether new starts are pinned to it. A rollout without workers or pending
//! tasks still gets a row.
use super::{
    Result,
    backend::Backend,
    query_authority::QueryTaskConfig,
    schedule_cron::php_compare,
    store::{Store, now},
    task_queues::json_time,
};
use chrono::{DateTime, Duration, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// PHP's group key for workers without a build id.
const UNVERSIONED: &str = "__unversioned__";

/// PHP's `trim`.
fn php_trim(text: &str) -> &str {
    text.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b'])
}

/// A trimmed, non-empty string, as PHP reads a build id, runtime or SDK version.
fn non_empty(text: Option<String>) -> Option<String> {
    text.map(|text| php_trim(&text).to_owned())
        .filter(|text| !text.is_empty())
}

/// Whether a PHP array stores this string key as an integer.
fn php_integer_key(key: &str) -> bool {
    let digits = key.strip_prefix('-').unwrap_or(key);
    !digits.is_empty()
        && digits.bytes().all(|byte| byte.is_ascii_digit())
        && (!digits.starts_with('0') || key == "0")
        && key.parse::<i64>().is_ok()
}

/// `sort(array_keys($set))`: PHP 8's standard comparison, and integer-like
/// keys come back as integers.
fn php_sorted_keys(mut keys: Vec<String>) -> Value {
    keys.sort_by(|left, right| php_compare(left, right));
    Value::Array(
        keys.into_iter()
            .map(|key| match key.parse::<i64>() {
                Ok(number) if php_integer_key(&key) => json!(number),
                _ => json!(key),
            })
            .collect(),
    )
}

fn insert_once(list: &mut Vec<String>, value: String) {
    if !list.contains(&value) {
        list.push(value);
    }
}

/// `workflowDefinitionFingerprints`: one worker's trimmed, non-empty type and
/// fingerprint pairs. PHP decodes the column to an array, whose integer-like
/// keys (and every key of a JSON list) are integers and fail its string check.
fn worker_fingerprints(value: Option<Value>) -> Vec<(String, String)> {
    let Some(Value::Object(map)) = value else {
        return Vec::new();
    };
    let mut pairs: Vec<(String, String)> = Vec::new();
    for (workflow_type, fingerprint) in &map {
        let Some(fingerprint) = fingerprint.as_str() else {
            continue;
        };
        if php_integer_key(workflow_type) {
            continue;
        }
        let (workflow_type, fingerprint) = (php_trim(workflow_type), php_trim(fingerprint));
        if workflow_type.is_empty() || fingerprint.is_empty() {
            continue;
        }
        match pairs.iter_mut().find(|(known, _)| known == workflow_type) {
            Some(pair) => pair.1 = fingerprint.to_owned(),
            None => pairs.push((workflow_type.to_owned(), fingerprint.to_owned())),
        }
    }
    pairs
}

/// One build id's workers, as `buildIdEntriesForTaskQueue` groups them.
struct Cohort {
    key: String,
    build_id: Option<String>,
    active: i64,
    draining: i64,
    stale: i64,
    total: i64,
    runtimes: Vec<String>,
    sdk_versions: Vec<String>,
    /// Each workflow type's distinct fingerprints.
    fingerprints: Vec<(String, Vec<String>)>,
    last_heartbeat: Option<DateTime<Utc>>,
    first_seen: Option<DateTime<Utc>>,
}

impl Cohort {
    fn new(key: String, build_id: Option<String>) -> Self {
        Self {
            key,
            build_id,
            active: 0,
            draining: 0,
            stale: 0,
            total: 0,
            runtimes: Vec::new(),
            sdk_versions: Vec::new(),
            fingerprints: Vec::new(),
            last_heartbeat: None,
            first_seen: None,
        }
    }

    /// `buildIdRolloutStatus`.
    fn rollout_status(&self, intent_draining: bool) -> &'static str {
        if self.active > 0 {
            if intent_draining || self.draining > 0 { "active_with_draining" } else { "active" }
        } else if self.draining > 0 || intent_draining {
            "draining"
        } else if self.stale > 0 {
            "stale_only"
        } else {
            "no_workers"
        }
    }

    /// `fingerprintSummary`: the fingerprint count and the workflow types
    /// with more than one fingerprint, by type.
    fn fingerprint_summary(&self) -> (usize, Value) {
        let count = self.fingerprints.iter().map(|(_, fingerprints)| fingerprints.len()).sum();
        let mut conflicts: Vec<(&String, usize)> = self
            .fingerprints
            .iter()
            .filter(|(_, fingerprints)| fingerprints.len() > 1)
            .map(|(workflow_type, fingerprints)| (workflow_type, fingerprints.len()))
            .collect();
        conflicts.sort_by(|left, right| left.0.cmp(right.0));
        let conflicts = conflicts
            .into_iter()
            .map(|(workflow_type, count)| json!({"workflow_type":workflow_type,"fingerprint_count":count}))
            .collect();
        (count, Value::Array(conflicts))
    }
}

/// `buildIdRolloutRank`: by status, the unversioned cohort after a build id.
fn rollout_rank(status: &str, build_id: Option<&str>) -> i64 {
    let rank = match status {
        "active" => 0,
        "active_with_draining" => 1,
        "draining" => 2,
        "stale_only" => 3,
        _ => 4,
    };
    rank * 2 + i64::from(build_id.is_none())
}

/// One build id's ready and leased workflow tasks.
struct Pending {
    build_id: Option<String>,
    total: i64,
    ready: i64,
    leased: i64,
}

impl Pending {
    /// `emptyPendingWorkflowTasks`.
    fn empty(build_id: Option<String>) -> Self {
        Self { build_id, total: 0, ready: 0, leased: 0 }
    }

    /// `pendingWorkflowTaskDiagnostic`.
    fn diagnostic(&self, active_workers: i64) -> Value {
        let (mut status, mut signal, mut message) = ("idle", None, None);
        if self.total > 0 {
            status = "pending";
            if self.build_id.is_some() && active_workers == 0 {
                status = "no_compatible_worker";
                signal = Some("no_compatible_worker");
                message = Some(format!(
                    "This build id has {} pending workflow task{} but no active compatible worker.",
                    self.total,
                    if self.total == 1 { "" } else { "s" }
                ));
            }
        }
        json!({"status":status,"operator_visible_signal":signal,"message":message,
            "total_count":self.total,"ready_count":self.ready,"leased_count":self.leased})
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
    /// The queue's registrations, newest heartbeat first and then by worker
    /// id, grouped by trimmed build id in first-seen order.
    async fn build_id_cohorts(&self, queue: &str, at: DateTime<Utc>, stale_after: i64) -> Result<Vec<Cohort>> {
        let rows = Self::query("SELECT build_id,status,runtime,sdk_version,workflow_definition_fingerprints,last_heartbeat_at,created_at FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 ORDER BY last_heartbeat_at DESC,worker_id")
            .bind(self.namespace.as_ref())
            .bind(queue)
            .fetch_all(&self.pool)
            .await?;
        let stale_before = at - Duration::seconds(stale_after);
        let mut cohorts: Vec<Cohort> = Vec::new();
        for row in &rows {
            let build_id = non_empty(DB::optional_string(row, "build_id")?);
            let key = build_id.clone().unwrap_or_else(|| UNVERSIONED.to_owned());
            let index = match cohorts.iter().position(|cohort| cohort.key == key) {
                Some(index) => index,
                None => {
                    cohorts.push(Cohort::new(key, build_id));
                    cohorts.len() - 1
                }
            };
            let cohort = &mut cohorts[index];
            let heartbeat = DB::optional_instant(row, "last_heartbeat_at")?;
            // A worker that never heartbeated is not stale. The declared
            // status is compared untrimmed, and any other value is active.
            let stale = heartbeat.is_some_and(|heartbeat| heartbeat < stale_before);
            cohort.total += 1;
            match DB::optional_string(row, "status")?.as_deref() {
                _ if stale => cohort.stale += 1,
                Some("stale") => cohort.stale += 1,
                Some("draining") => cohort.draining += 1,
                _ => cohort.active += 1,
            }
            if let Some(runtime) = non_empty(DB::optional_string(row, "runtime")?) {
                insert_once(&mut cohort.runtimes, runtime);
            }
            if let Some(version) = non_empty(DB::optional_string(row, "sdk_version")?) {
                insert_once(&mut cohort.sdk_versions, version);
            }
            // PHP's array cast reads a column that is not JSON as null.
            let advertised = DB::optional_document_row(row, "workflow_definition_fingerprints").ok().flatten();
            for (workflow_type, fingerprint) in worker_fingerprints(advertised) {
                match cohort.fingerprints.iter_mut().find(|(known, _)| *known == workflow_type) {
                    Some((_, fingerprints)) => insert_once(fingerprints, fingerprint),
                    None => cohort.fingerprints.push((workflow_type, vec![fingerprint])),
                }
            }
            if let Some(heartbeat) = heartbeat
                && cohort.last_heartbeat.is_none_or(|latest| heartbeat > latest)
            {
                cohort.last_heartbeat = Some(heartbeat);
            }
            if let Some(created) = DB::optional_instant(row, "created_at")?
                && cohort.first_seen.is_none_or(|first| created < first)
            {
                cohort.first_seen = Some(created);
            }
        }
        Ok(cohorts)
    }

    /// `pendingWorkflowTaskCountsForTaskQueue`: ready and leased workflow
    /// tasks counted by the task's trimmed build id, else its run's, keyed as
    /// `WorkerBuildIdRollout::buildIdKey` keys them.
    async fn pending_workflow_tasks(&self, queue: &str) -> Result<Vec<(String, Pending)>> {
        let rows = Self::query("SELECT t.status,COALESCE(NULLIF(TRIM(t.compatibility),''),NULLIF(TRIM(r.compatibility),'')) AS effective_compatibility,COUNT(*) AS task_count FROM workflow_tasks t LEFT JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.task_type='workflow' AND t.queue=$2 AND t.status IN ('ready','leased') GROUP BY t.status,COALESCE(NULLIF(TRIM(t.compatibility),''),NULLIF(TRIM(r.compatibility),''))")
            .bind(self.namespace.as_ref())
            .bind(queue)
            .fetch_all(&self.pool)
            .await?;
        let mut groups = rows
            .iter()
            .map(|row| {
                Ok((
                    DB::optional_string(row, "status")?,
                    DB::optional_string(row, "effective_compatibility")?,
                    DB::number(row, "task_count")?,
                ))
            })
            .collect::<Result<Vec<_>>>()?;
        // PHP's query has no ORDER BY. Read its groups in SQLite's grouping
        // order, status and then build id, on every backend.
        groups.sort();
        let mut counts: Vec<(String, Pending)> = Vec::new();
        for (status, compatibility, count) in groups {
            let build_id = non_empty(compatibility);
            let key = build_id.clone().unwrap_or_default();
            let index = match counts.iter().position(|(known, _)| *known == key) {
                Some(index) => index,
                None => {
                    counts.push((key, Pending::empty(build_id)));
                    counts.len() - 1
                }
            };
            let count = count.max(0);
            if count == 0 {
                continue;
            }
            let pending = &mut counts[index].1;
            pending.total += count;
            if non_empty(status).as_deref() == Some("leased") {
                pending.leased += count;
            } else {
                pending.ready += count;
            }
        }
        Ok(counts)
    }

    /// `GET /api/task-queues/{name}/build-ids`.
    pub(crate) async fn task_queue_build_ids(&self, queue: &str, config: &QueryTaskConfig) -> Result<Value> {
        let stale_after = config.worker_stale_after_seconds.max(1);
        let mut cohorts = self.build_id_cohorts(queue, now(), stale_after).await?;
        let pending = self.pending_workflow_tasks(queue).await?;
        let mut tx = self.begin().await?;
        let rollouts = Self::rollouts(&mut tx, self.namespace.as_ref(), queue).await?;
        tx.commit().await?;
        let selected = super::deployments::selected_key(&rollouts).map(str::to_owned);
        // A build id with pending workflow tasks and no worker still gets a
        // row; one with a rollout gets its row from the rollout below.
        for (key, counts) in &pending {
            let group = if key.is_empty() { UNVERSIONED } else { key.as_str() };
            if !cohorts.iter().any(|cohort| cohort.key == group) && !rollouts.iter().any(|rollout| &rollout.key == key) {
                cohorts.push(Cohort::new(group.to_owned(), counts.build_id.clone()));
            }
        }
        let grouped: Vec<String> = cohorts.iter().map(|cohort| cohort.key.clone()).collect();
        // A rollout no worker or pending task names is a row of its own.
        for rollout in &rollouts {
            let group = if rollout.key.is_empty() { UNVERSIONED } else { rollout.key.as_str() };
            if !grouped.iter().any(|key| key == group) {
                let build = (!rollout.key.is_empty()).then(|| rollout.key.clone());
                cohorts.push(Cohort::new(group.to_owned(), build));
            }
        }
        let mut entries: Vec<(i64, String, Value)> = cohorts
            .into_iter()
            .map(|cohort| {
                let rollout_key = cohort.build_id.as_deref().unwrap_or("");
                let rollout = rollouts.iter().find(|rollout| rollout.key == rollout_key);
                let intent = rollout.map_or("active", |rollout| rollout.drain_intent.as_str());
                let status = cohort.rollout_status(intent == super::deployments::DRAINING);
                let empty = Pending::empty(cohort.build_id.clone());
                let counts = pending
                    .iter()
                    .find(|(key, _)| key == rollout_key)
                    .map_or(&empty, |(_, counts)| counts);
                let (fingerprint_count, conflicts) = cohort.fingerprint_summary();
                let last_heartbeat = json_time(cohort.last_heartbeat);
                let order = last_heartbeat.as_str().unwrap_or("").to_owned();
                let entry = json!({"build_id":cohort.build_id,"rollout_status":status,"drain_intent":intent,
                    "drained_at":super::deployments::zulu(rollout.and_then(|rollout| rollout.drained_at)),
                    "promoted_at":super::deployments::zulu(rollout.and_then(|rollout| rollout.promoted_at)),
                    "rolled_back_at":super::deployments::zulu(rollout.and_then(|rollout| rollout.rolled_back_at)),
                    "new_start_selected":selected.as_deref() == Some(rollout_key),
                    "active_worker_count":cohort.active,"draining_worker_count":cohort.draining,
                    "stale_worker_count":cohort.stale,"total_worker_count":cohort.total,
                    "runtimes":php_sorted_keys(cohort.runtimes),"sdk_versions":php_sorted_keys(cohort.sdk_versions),
                    "workflow_definition_fingerprint_count":fingerprint_count,
                    "workflow_definition_fingerprint_conflicts":conflicts,
                    "last_heartbeat_at":last_heartbeat,"first_seen_at":json_time(cohort.first_seen),
                    "pending_workflow_tasks":counts.diagnostic(cohort.active)});
                (rollout_rank(status, cohort.build_id.as_deref()), order, entry)
            })
            .collect();
        // A stable sort: by rank, then the latest heartbeat first.
        entries.sort_by(|left, right| left.0.cmp(&right.0).then_with(|| right.1.cmp(&left.1)));
        Ok(json!({"namespace":self.namespace.as_ref(),"task_queue":queue,"stale_after_seconds":stale_after,
            "build_ids":entries.into_iter().map(|(_, _, entry)| entry).collect::<Vec<_>>()}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_array_keys_and_sorting() {
        assert!(php_integer_key("0") && php_integer_key("7") && php_integer_key("-12"));
        assert!(!php_integer_key("-0") && !php_integer_key("07") && !php_integer_key(" 7"));
        assert!(!php_integer_key("1.0") && !php_integer_key("") && !php_integer_key("99999999999999999999"));
        assert_eq!(
            php_sorted_keys(vec!["python".into(), "10".into(), "php".into(), "9".into()]),
            json!([9, 10, "php", "python"])
        );
        assert_eq!(php_sorted_keys(vec!["1.9.0".into(), "1.10.0".into()]), json!(["1.10.0", "1.9.0"]));
        assert_eq!(php_sorted_keys(vec!["1.00".into(), "1.0".into()]), json!(["1.00", "1.0"]));
    }

    #[test]
    fn fingerprints_follow_php_array_keys() {
        let pairs = worker_fingerprints(Some(json!({" Order ":" a ","7":"x"," 8":"y","Blank":" ","Number":1})));
        assert_eq!(
            pairs,
            vec![("8".to_owned(), "y".to_owned()), ("Order".to_owned(), "a".to_owned())]
        );
        assert!(worker_fingerprints(Some(json!(["a", "b"]))).is_empty());
        assert!(worker_fingerprints(None).is_empty());
    }

    #[test]
    fn pending_diagnostics_name_missing_workers() {
        let pinned = Pending { build_id: Some("v1".into()), total: 2, ready: 1, leased: 1 };
        assert_eq!(pinned.diagnostic(0)["message"], "This build id has 2 pending workflow tasks but no active compatible worker.");
        assert_eq!(pinned.diagnostic(1)["status"], "pending");
        let unversioned = Pending { build_id: None, total: 1, ready: 1, leased: 0 };
        assert_eq!(unversioned.diagnostic(0)["operator_visible_signal"], Value::Null);
        assert_eq!(Pending::empty(None).diagnostic(0)["status"], "idle");
    }
}
