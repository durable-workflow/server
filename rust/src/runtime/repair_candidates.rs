//! `TaskRepairCandidates::snapshot`: the tasks and runs that published PHP's
//! repair pass would select, across every namespace, grouped by connection,
//! queue and compatibility, with its scope-fair round-robin selection.
use chrono::{DateTime, Utc};
use serde_json::{Value, json};

/// `TaskRepairPolicy::SCAN_LIMIT`.
pub(super) const SCAN_LIMIT: usize = 25;

/// `missingTaskRunQuery`: open runs without a next task that need repair, or
/// that wait for children which have all closed while no workflow task is open.
macro_rules! missing_task_runs {
    () => {
        "(liveness_state='repair_needed' OR (liveness_state='waiting_for_child' \
         AND NOT EXISTS (SELECT 1 FROM workflow_tasks t WHERE t.workflow_run_id=workflow_run_summaries.id \
           AND t.task_type='workflow' AND t.status IN ('ready','leased')) \
         AND EXISTS (SELECT 1 FROM workflow_links l WHERE l.parent_workflow_run_id=workflow_run_summaries.id \
           AND l.link_type='child_workflow') \
         AND NOT EXISTS (SELECT 1 FROM workflow_links l WHERE l.parent_workflow_run_id=workflow_run_summaries.id \
           AND l.link_type='child_workflow' AND (NOT EXISTS (SELECT 1 FROM workflow_runs c WHERE c.id=l.child_workflow_run_id) \
             OR EXISTS (SELECT 1 FROM workflow_runs c WHERE c.id=l.child_workflow_run_id \
               AND c.status IN ('pending','running','waiting')))))) \
         AND next_task_id IS NULL AND status IN ('pending','running','waiting')"
    };
}

pub(super) const MISSING_TASK_RUNS: &str = missing_task_runs!();
/// `oldestMissingRunCandidate`, in the backend's own null ordering, as PHP's.
pub(super) const OLDEST_RUN: &str = concat!(
    "SELECT wait_started_at,started_at,created_at FROM workflow_run_summaries WHERE ",
    missing_task_runs!(),
    " ORDER BY wait_started_at,started_at,id LIMIT 1"
);

/// One `GROUP BY` row of candidates.
pub(super) struct ScopeRow {
    pub(super) connection: Option<String>,
    pub(super) queue: Option<String>,
    pub(super) compatibility: Option<String>,
    pub(super) count: usize,
    pub(super) oldest: Option<DateTime<Utc>>,
}

impl ScopeRow {
    /// `nullableString` of each scope column.
    pub(super) fn values(&self) -> [Option<&str>; 3] {
        [&self.connection, &self.queue, &self.compatibility]
            .map(|value| value.as_deref().filter(|value| !value.is_empty()))
    }

    fn key(&self) -> String {
        let [connection, queue, compatibility] = self.values();
        format!("{}:{}:{}", connection.unwrap_or("default"), queue.unwrap_or("default"), compatibility.unwrap_or("any"))
    }

    /// `applyScope`: an absent scope value matches null, empty or the public
    /// default; a present one matches exactly.
    fn within(&self, scope: &ScopeRow) -> bool {
        let defaults = ["default", "default", "any"];
        let raw = [&self.connection, &self.queue, &self.compatibility];
        scope.values().into_iter().zip(raw).zip(defaults).all(|((scope, raw), default)| match scope {
            None => raw.as_deref().is_none_or(|raw| raw.is_empty() || raw == default),
            Some(scope) => raw.as_deref() == Some(scope),
        })
    }
}

/// `normalizedScopes` with each scope's selected ids counted: oldest scope
/// first, each holding at most `limit` candidates.
fn buckets(rows: &[ScopeRow], limit: usize) -> Vec<(String, usize)> {
    normalized(rows)
        .into_iter()
        .map(|scope| {
            let count: usize = rows.iter().filter(|row| row.within(scope)).map(|row| row.count).sum();
            (scope.key(), count.min(limit))
        })
        .filter(|(_, count)| *count > 0)
        .collect()
}

/// `normalizedScopes`: one row per scope key, keeping its oldest, ordered by
/// oldest candidate and then key.
pub(super) fn normalized(rows: &[ScopeRow]) -> Vec<&ScopeRow> {
    let mut chosen: Vec<&ScopeRow> = Vec::new();
    for row in rows {
        match chosen.iter().position(|scope| scope.key() == row.key()) {
            None => chosen.push(row),
            Some(index) => {
                if row.oldest.is_some_and(|oldest| chosen[index].oldest.is_none_or(|current| oldest < current)) {
                    chosen[index] = row;
                }
            }
        }
    }
    chosen.sort_by(|left, right| {
        let order = |scope: &ScopeRow| scope.oldest.map_or(i64::MAX, |oldest| oldest.timestamp_millis());
        order(left).cmp(&order(right)).then_with(|| left.key().cmp(&right.key()))
    });
    chosen
}

/// `selectedScopeCounts`: one candidate from each scope in turn, up to
/// `limit` in all.
fn selected(buckets: &[(String, usize)], limit: usize) -> Vec<(String, usize)> {
    let mut remaining: Vec<usize> = buckets.iter().map(|(_, count)| *count).collect();
    let mut counts: Vec<(String, usize)> = buckets.iter().map(|(key, _)| (key.clone(), 0)).collect();
    let mut total = 0;
    while total < limit && remaining.iter().any(|count| *count > 0) {
        for (index, left) in remaining.iter_mut().enumerate() {
            if *left == 0 {
                continue;
            }
            *left -= 1;
            counts[index].1 += 1;
            total += 1;
            if total >= limit {
                break;
            }
        }
    }
    counts
}

fn count_for(counts: &[(String, usize)], key: &str) -> usize {
    counts.iter().find(|(scope, _)| scope == key).map_or(0, |(_, count)| *count)
}

fn age(oldest: Option<DateTime<Utc>>, now: DateTime<Utc>) -> i64 {
    oldest.map_or(0, |oldest| (now - oldest).num_milliseconds())
}

/// One scope of `scopeSnapshot`.
struct Scope<'a> {
    row: &'a ScopeRow,
    tasks: usize,
    runs: usize,
    oldest_task: Option<DateTime<Utc>>,
    oldest_run: Option<DateTime<Utc>>,
}

/// `scopeSnapshot`: every scope with candidates, the busiest and oldest
/// first. A scope keeps the columns of the first row seen with its key.
fn scopes(tasks: &[ScopeRow], runs: &[ScopeRow], selected_tasks: &[(String, usize)], selected_runs: &[(String, usize)], now: DateTime<Utc>) -> Vec<Value> {
    let mut scopes: Vec<Scope> = Vec::new();
    for (row, is_task) in tasks.iter().map(|row| (row, true)).chain(runs.iter().map(|row| (row, false))) {
        let index = match scopes.iter().position(|scope| scope.row.key() == row.key()) {
            Some(index) => index,
            None => {
                scopes.push(Scope { row, tasks: 0, runs: 0, oldest_task: None, oldest_run: None });
                scopes.len() - 1
            }
        };
        let scope = &mut scopes[index];
        let (count, oldest) = if is_task { (&mut scope.tasks, &mut scope.oldest_task) } else { (&mut scope.runs, &mut scope.oldest_run) };
        *count += row.count;
        if row.oldest.is_some_and(|at| oldest.is_none_or(|current| at < current)) {
            *oldest = row.oldest;
        }
    }
    let mut documents: Vec<(usize, i64, String, Value)> = scopes
        .into_iter()
        .map(|scope| {
            let key = scope.row.key();
            let [connection, queue, compatibility] = scope.row.values();
            let (picked_tasks, picked_runs) = (count_for(selected_tasks, &key), count_for(selected_runs, &key));
            let total = scope.tasks + scope.runs;
            let (task_age, run_age) = (age(scope.oldest_task, now), age(scope.oldest_run, now));
            let document = json!({"scope_key": key, "connection": connection, "queue": queue, "compatibility": compatibility,
                "existing_task_candidates": scope.tasks, "missing_task_candidates": scope.runs, "total_candidates": total,
                "selected_existing_task_candidates": picked_tasks, "selected_missing_task_candidates": picked_runs,
                "selected_total_candidates": picked_tasks + picked_runs,
                "oldest_task_candidate_created_at": super::task_queues::json_time(scope.oldest_task),
                "oldest_missing_run_started_at": super::task_queues::json_time(scope.oldest_run),
                "max_task_candidate_age_ms": task_age, "max_missing_run_age_ms": run_age,
                "scan_limited_by_global_policy": total > picked_tasks + picked_runs});
            (total, task_age.max(run_age), key, document)
        })
        .collect();
    documents.sort_by(|left, right| right.0.cmp(&left.0).then(right.1.cmp(&left.1)).then_with(|| left.2.cmp(&right.2)));
    documents.into_iter().map(|(_, _, _, document)| document).collect()
}

/// The snapshot from the grouped task and run candidates and the oldest
/// missing run's time.
pub(super) fn snapshot(tasks: &[ScopeRow], runs: &[ScopeRow], oldest_run: Option<DateTime<Utc>>, now: DateTime<Utc>) -> Value {
    let task_count: usize = tasks.iter().map(|row| row.count).sum();
    let run_count: usize = runs.iter().map(|row| row.count).sum();
    let selected_tasks = selected(&buckets(tasks, SCAN_LIMIT), SCAN_LIMIT);
    let selected_runs = selected(&buckets(runs, SCAN_LIMIT), SCAN_LIMIT);
    let picked_tasks: usize = selected_tasks.iter().map(|(_, count)| count).sum();
    let picked_runs: usize = selected_runs.iter().map(|(_, count)| count).sum();
    let oldest_task = tasks.iter().filter_map(|row| row.oldest).min();
    json!({
        "existing_task_candidates": task_count, "missing_task_candidates": run_count,
        "total_candidates": task_count + run_count, "scan_limit": SCAN_LIMIT, "scan_strategy": "scope_fair_round_robin",
        "selected_existing_task_candidates": picked_tasks, "selected_missing_task_candidates": picked_runs,
        "selected_total_candidates": picked_tasks + picked_runs,
        "existing_task_scan_limit_reached": task_count >= SCAN_LIMIT,
        "missing_task_scan_limit_reached": run_count >= SCAN_LIMIT,
        "scan_pressure": task_count >= SCAN_LIMIT || run_count >= SCAN_LIMIT,
        "oldest_task_candidate_created_at": super::task_queues::json_time(oldest_task),
        "oldest_missing_run_started_at": super::task_queues::json_time(oldest_run),
        "max_task_candidate_age_ms": age(oldest_task, now), "max_missing_run_age_ms": age(oldest_run, now),
        "scopes": scopes(tasks, runs, &selected_tasks, &selected_runs, now),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(queue: Option<&str>, count: usize, minute: u32) -> ScopeRow {
        let oldest = DateTime::parse_from_rfc3339(&format!("2026-10-21T12:{minute:02}:00Z")).unwrap().with_timezone(&Utc);
        ScopeRow { connection: None, queue: queue.map(str::to_owned), compatibility: None, count, oldest: Some(oldest) }
    }

    #[test]
    fn selection_takes_one_candidate_from_each_scope_in_turn() {
        // The oldest scope goes first; each bucket holds at most the limit.
        let rows = [row(Some("busy"), 40, 5), row(Some("quiet"), 2, 1), row(None, 3, 9)];
        let buckets = buckets(&rows, SCAN_LIMIT);
        assert_eq!(buckets, [("default:quiet:any".to_owned(), 2), ("default:busy:any".to_owned(), 25), ("default:default:any".to_owned(), 3)]);
        let picked = selected(&buckets, SCAN_LIMIT);
        assert_eq!(picked, [("default:quiet:any".to_owned(), 2), ("default:busy:any".to_owned(), 20), ("default:default:any".to_owned(), 3)]);
    }

    #[test]
    fn empty_and_default_values_share_a_scope_like_php() {
        let rows = [row(None, 1, 3), row(Some(""), 2, 2), row(Some("default"), 4, 1)];
        // The literal `default` row is oldest, so it represents the scope and
        // its exact value selects only its own rows.
        assert_eq!(buckets(&rows, SCAN_LIMIT), [("default:default:any".to_owned(), 4)]);
        let now = DateTime::parse_from_rfc3339("2026-10-21T12:10:00Z").unwrap().with_timezone(&Utc);
        let document = snapshot(&rows, &[], None, now);
        assert_eq!(document["existing_task_candidates"], 7);
        assert_eq!(document["scopes"][0]["existing_task_candidates"], 7);
        assert_eq!(document["scopes"][0]["scan_limited_by_global_policy"], true);
        assert_eq!(document["oldest_task_candidate_created_at"], "2026-10-21T12:01:00.000000Z");
        assert_eq!(document["max_task_candidate_age_ms"], 540_000);
    }
}
