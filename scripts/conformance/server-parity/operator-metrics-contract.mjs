import assert from 'node:assert/strict';
import {isDeepStrictEqual} from 'node:util';

// Reviewed operator metrics and health of one namespace, as published PHP
// 2.5.14 reports them through a run's stages. Counts must match exactly, with
// two normalizations:
// - Laravel compares "now" in whole seconds, so a task made available within
//   the current second counts as delayed. Ready-due and delayed tasks are
//   compared as one total, and runnable and delayed backlog likewise.
// - `tasks_dispatched_last_minute` is left out: PHP stamps a dispatch on some
//   new tasks and not on an activity scheduled by a workflow task completion.
// Elapsed ages and summary history byte sizes are not compared. Each
// `oldest_*` timestamp must be present exactly when its count is not zero.
// The selected-run projection check is not compared: PHP fills its timeline
// projections asynchronously, and native reads timelines from history.
const STAGES = ['empty', 'waiting', 'waiting_after_redispatch', 'activity_scheduled', 'activity_running',
  'activity_retrying', 'completed'];
const CHECKS = [
  ['backend_capabilities', 'correctness'], ['run_summary_projection', 'correctness'],
  ['selected_run_projections', 'correctness'], ['history_retention_invariant', 'correctness'],
  ['command_contract_snapshots', 'correctness'], ['task_transport', 'correctness'], ['activity_path', 'correctness'],
  ['routing_health', 'correctness'], ['durable_resume_paths', 'correctness'], ['worker_compatibility', 'correctness'],
  ['scheduler_role', 'correctness'], ['long_poll_wake_acceleration', 'acceleration'],
];
const UNCOMPARED_CHECK = 'selected_run_projections';
const TIMESTAMPS = {
  runs: {oldest_repair_needed_at: 'repair_needed', oldest_wait_started_at: 'waiting'},
  tasks: {oldest_lease_expired_at: 'lease_expired', oldest_ready_due_at: 'ready_due',
    oldest_dispatch_overdue_since: 'dispatch_overdue', oldest_claim_failed_at: 'claim_failed',
    oldest_dispatch_failed_at: 'dispatch_failed', oldest_unhealthy_at: 'unhealthy'},
  activities: {oldest_retrying_started_at: 'retrying', oldest_timeout_overdue_at: 'timeout_overdue'},
  backlog: {oldest_compatibility_blocked_started_at: 'compatibility_blocked_runs'},
  starts: {oldest_pending_start_at: 'pending_runs'},
  schedules: {oldest_overdue_at: 'missed'},
};
const THRESHOLDS = {event_threshold: 10000, size_bytes_threshold: 5242880, fan_out_threshold: 200,
  event_warning_threshold: 8000, size_bytes_warning_threshold: 4194304, fan_out_warning_threshold: 160};
const IGNORED = {backlog: ['tasks_dispatched_last_minute'], history: ['max_size_bytes', ...Object.keys(THRESHOLDS)]};

const waitingRun = {total: 1, current: 1, running: 1, waiting: 1};
const missingContract = {backfill_needed_runs: 1, backfill_unavailable_runs: 1, actionable_backfill_needed_runs: 1,
  actionable_backfill_unavailable_runs: 1};
const started = {starts: {pending_runs: 1, pending_commands: 1}};
// Nonzero counts at each stage; every other count is zero.
const EXPECTED = {
  empty: {},
  waiting: {runs: waitingRun, tasks: {open: 1, ready: 1, ready_due_or_delayed: 1},
    backlog: {runnable_or_delayed_tasks: 1, tasks_added_last_minute: 1}, ...started,
    history: {events: 2, max_event_count: 2}, command_contracts: missingContract},
  waiting_after_redispatch: {runs: waitingRun,
    tasks: {open: 1, ready: 1, ready_due_or_delayed: 1, dispatch_overdue: 1, unhealthy: 1},
    backlog: {runnable_or_delayed_tasks: 1, tasks_added_last_minute: 1, unhealthy_tasks: 1},
    ...started,
    history: {events: 2, max_event_count: 2}, command_contracts: missingContract},
  activity_scheduled: {runs: waitingRun, tasks: {open: 1, ready: 1, ready_due_or_delayed: 1},
    activities: {open: 1, pending: 1, queued_open: 1, queued_pending: 1},
    backlog: {runnable_or_delayed_tasks: 1, tasks_added_last_minute: 2},
    history: {events: 3, max_event_count: 3}, command_contracts: missingContract},
  activity_running: {runs: waitingRun, tasks: {open: 1, leased: 1, max_attempt_count: 1},
    activities: {open: 1, running: 1, queued_open: 1, queued_running: 1, max_attempt_count: 1},
    backlog: {leased_tasks: 1, tasks_added_last_minute: 2},
    history: {events: 4, max_event_count: 4}, command_contracts: missingContract},
  activity_retrying: {runs: waitingRun, tasks: {open: 1, ready: 1, ready_due_or_delayed: 1, max_attempt_count: 1},
    activities: {open: 1, pending: 1, queued_open: 1, queued_pending: 1, queued_failed_attempts: 1, retrying: 1,
      failed_attempts: 1, max_attempt_count: 1},
    backlog: {runnable_or_delayed_tasks: 1, tasks_added_last_minute: 3, retrying_activities: 1},
    history: {events: 5, max_event_count: 5}, command_contracts: missingContract},
  completed: {runs: {total: 1, current: 1, completed: 1},
    activities: {queued_failed_attempts: 1, failed_attempts: 1, max_attempt_count: 2},
    backlog: {tasks_added_last_minute: 4}, history: {events: 8, max_event_count: 8},
    command_contracts: {backfill_needed_runs: 1, backfill_unavailable_runs: 1, closed_backfill_needed_runs: 1}},
};
// PHP's maintenance loop redispatches an overdue ready task (TaskRepair), so
// past the redispatch window published PHP may already show the task repaired
// instead of overdue. Native runs no repair loop and keeps reporting it overdue.
const REPAIRED = {...EXPECTED.waiting_after_redispatch, tasks: {open: 1, ready: 1, ready_due_or_delayed: 1},
  backlog: {runnable_or_delayed_tasks: 1, tasks_added_last_minute: 1}};
const REPAIRED_WARNINGS = ['command_contract_snapshots'];
// PHP's TaskWatchdog repairs the task and projects the run before
// TaskDispatcher redispatches it and projects the run again. Until then the
// task is still overdue and the run reads as needing repair.
const REPAIRING = {...EXPECTED.waiting_after_redispatch, runs: {...waitingRun, repair_needed: 1},
  backlog: {...EXPECTED.waiting_after_redispatch.backlog, repair_needed_runs: 1}};
const REPAIRING_WARNINGS = ['command_contract_snapshots', 'task_transport', 'routing_health', 'durable_resume_paths'];
// Checks other than ok at each stage (the uncompared check aside).
const WARNINGS = {
  empty: [],
  waiting: ['command_contract_snapshots'],
  waiting_after_redispatch: ['command_contract_snapshots', 'task_transport', 'routing_health'],
  activity_scheduled: ['command_contract_snapshots'],
  activity_running: ['command_contract_snapshots'],
  activity_retrying: ['command_contract_snapshots'],
  completed: [],
};

// Past the redispatch window published PHP is in one of these states, in
// order: overdue, repairing, or redispatched. Metrics and health are separate
// requests, so its health may be at the state its metrics show or a later one.
// Native runs no repair loop and is held to the state its metrics show.
const REPAIR_STATES = [
  {counts: EXPECTED.waiting_after_redispatch, warnings: WARNINGS.waiting_after_redispatch},
  {counts: REPAIRING, warnings: REPAIRING_WARNINGS},
  {counts: REPAIRED, warnings: REPAIRED_WARNINGS},
];

function normalized(sections) {
  const counts = {};
  for (const [name, section] of Object.entries(sections)) {
    const values = {...section.counts};
    for (const field of IGNORED[name] ?? []) delete values[field];
    counts[name] = values;
  }
  const {tasks, backlog} = counts;
  tasks.ready_due_or_delayed = tasks.ready_due + tasks.delayed;
  backlog.runnable_or_delayed_tasks = backlog.runnable_tasks + backlog.delayed_tasks;
  for (const [section, fields] of [[tasks, ['ready_due', 'delayed']], [backlog, ['runnable_tasks', 'delayed_tasks']],
    [counts.starts, ['ready_tasks']]]) {
    for (const field of fields) delete section[field];
  }
  return counts;
}

// Published PHP's health query is invalid SQL on MariaDB once an activity
// exists (server #569). Only that target there may answer 500 from then on.
const MARIADB_HEALTH_DEFECT_STAGES = ['activity_scheduled', 'activity_running', 'activity_retrying', 'completed'];

export function publishedMariaDbHealthDefect(name, stage, family) {
  return family === 'mariadb' && stage.runtime === 'php' && stage.health_status === 500
    && MARIADB_HEALTH_DEFECT_STAGES.includes(name);
}

function checkStage(name, stage, namespace, family) {
  const label = `operator metrics stage ${name}`;
  const defect = publishedMariaDbHealthDefect(name, stage, family);
  assert.deepStrictEqual([stage.metrics_status, stage.metrics_namespace, defect ? namespace : stage.health_namespace, stage.expected_namespace],
    [200, namespace, namespace, namespace], `${label} namespace`);
  assert.deepStrictEqual(stage.metrics_keys, ['namespace', 'operator_metrics'], `${label} document`);
  for (const [section, pairs] of Object.entries(TIMESTAMPS)) {
    for (const [field, count] of Object.entries(pairs)) {
      assert.equal(stage.sections[section].timestamps[field], stage.sections[section].counts[count] > 0,
        `${label} ${section}.${field} present exactly when ${count} is not zero`);
    }
  }
  const raw = stage.sections;
  assert.deepStrictEqual(Object.fromEntries(Object.keys(THRESHOLDS).map(key => [key, raw.history.counts[key]])), THRESHOLDS,
    `${label} history budget thresholds`);
  // A starting run's ready task counts once it is due. Past the redispatch
  // window the waiting task is due whatever the clock's second.
  assert.ok(raw.starts.counts.ready_tasks <= raw.tasks.counts.ready_due, `${label} ready start tasks are due`);
  if (name === 'waiting_after_redispatch') {
    assert.deepStrictEqual([raw.tasks.counts.ready_due, raw.starts.counts.ready_tasks], [1, 1], `${label} due task`);
  }
  const counts = normalized(raw);
  const redispatch = name === 'waiting_after_redispatch';
  const php = redispatch && stage.runtime === 'php';
  const repaired = redispatch && counts.tasks.max_repair_count > 0;
  if (repaired) delete counts.tasks.max_repair_count;
  const states = redispatch ? REPAIR_STATES : [{counts: EXPECTED[name], warnings: WARNINGS[name]}];
  // A repair count means PHP repaired the task: mid-repair (PHP only) or redispatched.
  const possible = !repaired ? [0] : php ? [1, 2] : [2];
  const nonzero = values => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== 0));
  const matches = index => Object.entries(counts)
    .every(([section, values]) => isDeepStrictEqual(nonzero(values), states[index].counts[section] ?? {}));
  const state = possible.find(matches) ?? possible[possible.length - 1];
  for (const [section, values] of Object.entries(counts)) {
    assert.deepStrictEqual(nonzero(values), states[state].counts[section] ?? {}, `${label} ${section} counts`);
  }
  assert.equal(stage.active_workers, ['empty', 'waiting', 'waiting_after_redispatch'].includes(name) ? 0 : 1,
    `${label} active workers`);
  if (defect) return counts;
  assert.deepStrictEqual(stage.checks.map(check => [check.name, check.category]), CHECKS, `${label} check names`);
  const statuses = stage.checks.filter(check => check.name !== UNCOMPARED_CHECK);
  assert.ok(statuses.every(check => ['ok', 'warning'].includes(check.status)), `${label} no check errors`);
  const warnings = statuses.filter(check => check.status !== 'ok').map(check => check.name);
  const allowed = (php ? states.slice(state) : [states[state]]).map(candidate => candidate.warnings);
  const settled = allowed.find(list => isDeepStrictEqual(list, warnings)) ?? states[state].warnings;
  assert.deepStrictEqual(warnings, settled, `${label} warnings`);
  const warning = settled.length > 0 || stage.checks.some(check => check.status === 'warning');
  assert.deepStrictEqual([stage.health_status, stage.health.status, stage.health.healthy], [200, warning ? 'warning' : 'ok', true],
    `${label} health`);
  assert.deepStrictEqual(stage.retention, ['bounded', 30], `${label} namespace retention`);
  return counts;
}

export function checkOperatorMetrics(fixture, observation, workflowId) {
  const state = observation.operator_metrics;
  assert.ok(state && typeof state === 'object', 'actual operator metrics observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_operator_metrics'},
      'embedded operator metrics explicitly inapplicable');
    return {scope: 'operator_metrics', stages: STAGES};
  }
  const namespace = `${workflowId}-metrics`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace, Object.keys(state.stages)], [true, namespace, STAGES],
    'operator metrics stages');
  assert.ok([null, 'mariadb'].includes(state.database_family ?? null), 'operator metrics database family');
  for (const name of STAGES) checkStage(name, state.stages[name], namespace, state.database_family ?? null);
  return {scope: 'operator_metrics', stages: STAGES};
}
