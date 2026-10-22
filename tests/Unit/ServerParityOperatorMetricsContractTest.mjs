import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkOperatorMetrics} from '../../scripts/conformance/server-parity/operator-metrics-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/operator-metrics.json', import.meta.url)));
const namespace = 'test-metrics';
const SECTIONS = {
  runs: ['total', 'current', 'running', 'completed', 'failed', 'cancelled', 'terminated', 'archived', 'repair_needed',
    'claim_failed', 'compatibility_blocked', 'waiting'],
  tasks: ['open', 'ready', 'ready_due', 'delayed', 'leased', 'dispatch_failed', 'claim_failed', 'dispatch_overdue',
    'lease_expired', 'max_attempt_count', 'max_repair_count', 'unhealthy'],
  activities: ['open', 'pending', 'running', 'local', 'local_open', 'local_pending', 'local_running', 'local_attempts',
    'local_failed_attempts', 'queued_open', 'queued_pending', 'queued_running', 'queued_failed_attempts', 'retrying',
    'timeout_overdue', 'failed_attempts', 'max_attempt_count'],
  backlog: ['runnable_tasks', 'delayed_tasks', 'leased_tasks', 'tasks_added_last_minute', 'tasks_dispatched_last_minute',
    'retrying_activities', 'unhealthy_tasks', 'repair_needed_runs', 'claim_failed_runs', 'compatibility_blocked_runs'],
  starts: ['pending_runs', 'pending_commands', 'ready_tasks'],
  history: ['continue_as_new_recommended_runs', 'approaching_budget_runs', 'events', 'history_orphan_total',
    'max_event_count', 'max_size_bytes', 'max_fan_out', 'event_threshold', 'size_bytes_threshold', 'fan_out_threshold',
    'event_warning_threshold', 'size_bytes_warning_threshold', 'fan_out_warning_threshold'],
  schedules: ['active', 'paused', 'missed', 'fires_total', 'failures_total'],
  command_contracts: ['backfill_needed_runs', 'backfill_available_runs', 'backfill_unavailable_runs',
    'actionable_backfill_needed_runs', 'actionable_backfill_available_runs', 'actionable_backfill_unavailable_runs',
    'closed_backfill_needed_runs'],
};
const STAMPS = {
  runs: {oldest_repair_needed_at: 'repair_needed', oldest_wait_started_at: 'waiting'},
  tasks: {oldest_lease_expired_at: 'lease_expired', oldest_ready_due_at: 'ready_due',
    oldest_dispatch_overdue_since: 'dispatch_overdue', oldest_claim_failed_at: 'claim_failed',
    oldest_dispatch_failed_at: 'dispatch_failed', oldest_unhealthy_at: 'unhealthy'},
  activities: {oldest_retrying_started_at: 'retrying', oldest_timeout_overdue_at: 'timeout_overdue'},
  backlog: {oldest_compatibility_blocked_started_at: 'compatibility_blocked_runs'},
  starts: {oldest_pending_start_at: 'pending_runs'},
  schedules: {oldest_overdue_at: 'missed'},
  history: {}, command_contracts: {},
};
const NAMES = ['backend_capabilities', 'run_summary_projection', 'selected_run_projections', 'history_retention_invariant',
  'command_contract_snapshots', 'task_transport', 'activity_path', 'routing_health', 'durable_resume_paths',
  'worker_compatibility', 'scheduler_role', 'long_poll_wake_acceleration'];

// PHP 2.5.14's recorded stages, with PHP's thresholds and sizes.
const WAITING = {runs: {total: 1, current: 1, running: 1, waiting: 1}, tasks: {open: 1, ready: 1, delayed: 1},
  backlog: {delayed_tasks: 1, tasks_added_last_minute: 1, tasks_dispatched_last_minute: 1},
  starts: {pending_runs: 1, pending_commands: 1}, history: {events: 2, max_event_count: 2},
  command_contracts: {backfill_needed_runs: 1, backfill_unavailable_runs: 1, actionable_backfill_needed_runs: 1,
    actionable_backfill_unavailable_runs: 1}};
const RECORDED = {
  empty: [{}, []],
  waiting: [WAITING, ['selected_run_projections', 'command_contract_snapshots']],
  waiting_after_redispatch: [{...WAITING,
    tasks: {open: 1, ready: 1, ready_due: 1, dispatch_overdue: 1, unhealthy: 1},
    backlog: {runnable_tasks: 1, tasks_added_last_minute: 1, tasks_dispatched_last_minute: 1, unhealthy_tasks: 1},
    starts: {pending_runs: 1, pending_commands: 1, ready_tasks: 1}},
  ['selected_run_projections', 'command_contract_snapshots', 'task_transport', 'routing_health']],
  activity_scheduled: [{...WAITING, tasks: {open: 1, ready: 1, delayed: 1}, starts: {},
    activities: {open: 1, pending: 1, queued_open: 1, queued_pending: 1},
    backlog: {delayed_tasks: 1, tasks_added_last_minute: 2, tasks_dispatched_last_minute: 1},
    history: {events: 3, max_event_count: 3, max_size_bytes: 3000}}, ['command_contract_snapshots']],
  activity_running: [{...WAITING, tasks: {open: 1, leased: 1, max_attempt_count: 1}, starts: {},
    activities: {open: 1, running: 1, queued_open: 1, queued_running: 1, max_attempt_count: 1},
    backlog: {leased_tasks: 1, tasks_added_last_minute: 2, tasks_dispatched_last_minute: 1},
    history: {events: 4, max_event_count: 4}}, ['command_contract_snapshots']],
  activity_retrying: [{...WAITING, tasks: {open: 1, ready: 1, delayed: 1, max_attempt_count: 1}, starts: {},
    activities: {open: 1, pending: 1, queued_open: 1, queued_pending: 1, queued_failed_attempts: 1, retrying: 1,
      failed_attempts: 1, max_attempt_count: 1},
    backlog: {delayed_tasks: 1, tasks_added_last_minute: 3, tasks_dispatched_last_minute: 2, retrying_activities: 1},
    history: {events: 5, max_event_count: 5}}, ['command_contract_snapshots']],
  completed: [{runs: {total: 1, current: 1, completed: 1},
    activities: {queued_failed_attempts: 1, failed_attempts: 1, max_attempt_count: 2},
    backlog: {tasks_added_last_minute: 4, tasks_dispatched_last_minute: 3}, history: {events: 8, max_event_count: 8},
    command_contracts: {backfill_needed_runs: 1, backfill_unavailable_runs: 1, closed_backfill_needed_runs: 1}}, []],
};

const THRESHOLDS = {event_threshold: 10000, size_bytes_threshold: 5242880, fan_out_threshold: 200,
  event_warning_threshold: 8000, size_bytes_warning_threshold: 4194304, fan_out_warning_threshold: 160};
function stage(name) {
  const [values, warnings] = RECORDED[name];
  const sections = {};
  for (const [section, fields] of Object.entries(SECTIONS)) {
    const counts = Object.fromEntries(fields.map(field => [field, values[section]?.[field]
      ?? (section === 'history' ? THRESHOLDS[field] : undefined) ?? 0]));
    const timestamps = Object.fromEntries(Object.entries(STAMPS[section]).map(([field, count]) => [field, counts[count] > 0]));
    sections[section] = {counts, timestamps};
  }
  const warned = new Set(warnings);
  return {metrics_status: 200, metrics_namespace: namespace, metrics_keys: ['namespace', 'operator_metrics'], sections,
    active_workers: ['empty', 'waiting', 'waiting_after_redispatch'].includes(name) ? 0 : 1, worker_fleet: [],
    runtime: 'php',
    health_status: 200, health_namespace: namespace, retention: ['bounded', 30],
    health: {status: warned.size ? 'warning' : 'ok', healthy: true, categories: {}},
    checks: NAMES.map(check => ({name: check, status: warned.has(check) ? 'warning' : 'ok',
      category: check === 'long_poll_wake_acceleration' ? 'acceleration' : 'correctness'})),
    expected_namespace: namespace};
}
const model = () => structuredClone({mode: 'http', operator_metrics: {applicable: true, namespace,
  stages: Object.fromEntries(Object.keys(RECORDED).map(name => [name, stage(name)]))}});
const projection = {scope: 'operator_metrics', stages: Object.keys(RECORDED)};

test('published PHP stages pass', () => assert.deepStrictEqual(checkOperatorMetrics(fixture, model(), 'TEST'), projection));
test('a task made due within the same second passes', () => {
  const observation = model();
  const tasks = observation.operator_metrics.stages.waiting.sections.tasks;
  Object.assign(tasks.counts, {ready_due: 1, delayed: 0});
  tasks.timestamps.oldest_ready_due_at = true;
  const backlog = observation.operator_metrics.stages.waiting.sections.backlog.counts;
  Object.assign(backlog, {runnable_tasks: 1, delayed_tasks: 0});
  observation.operator_metrics.stages.waiting.sections.starts.counts.ready_tasks = 1;
  assert.deepStrictEqual(checkOperatorMetrics(fixture, observation, 'TEST'), projection);
});
test('an uncompared projection check may differ', () => {
  const observation = model();
  observation.operator_metrics.stages.waiting.checks[2].status = 'ok';
  checkOperatorMetrics(fixture, observation, 'TEST');
});
test('a task PHP\'s maintenance loop already redispatched passes', () => {
  const observation = model();
  const stage = observation.operator_metrics.stages.waiting_after_redispatch;
  Object.assign(stage.sections.tasks.counts, {dispatch_overdue: 0, unhealthy: 0, max_repair_count: 1});
  Object.assign(stage.sections.tasks.timestamps, {oldest_dispatch_overdue_since: false, oldest_unhealthy_at: false});
  stage.sections.backlog.counts.unhealthy_tasks = 0;
  for (const check of stage.checks) if (['task_transport', 'routing_health'].includes(check.name)) check.status = 'ok';
  assert.deepStrictEqual(checkOperatorMetrics(fixture, observation, 'TEST'), projection);
});
const setChecks = (stage, statuses) => {
  for (const check of stage.checks) if (check.name in statuses) check.status = statuses[check.name];
};
test('published PHP health read while its maintenance loop repairs the task passes', () => {
  const observation = model();
  setChecks(observation.operator_metrics.stages.waiting_after_redispatch, {durable_resume_paths: 'warning'});
  assert.deepStrictEqual(checkOperatorMetrics(fixture, observation, 'TEST'), projection);
});
test('published PHP health read after its maintenance loop redispatched the task passes', () => {
  const observation = model();
  setChecks(observation.operator_metrics.stages.waiting_after_redispatch, {task_transport: 'ok', routing_health: 'ok'});
  assert.deepStrictEqual(checkOperatorMetrics(fixture, observation, 'TEST'), projection);
});
test('rejects native health reporting a repair native never runs', () => {
  const observation = model();
  const stage = observation.operator_metrics.stages.waiting_after_redispatch;
  stage.runtime = 'rust';
  setChecks(stage, {durable_resume_paths: 'warning'});
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'), /warnings/);
});
test('rejects native health reporting a redispatch native never runs', () => {
  const observation = model();
  const stage = observation.operator_metrics.stages.waiting_after_redispatch;
  stage.runtime = 'rust';
  setChecks(stage, {task_transport: 'ok', routing_health: 'ok'});
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'), /warnings/);
});
test('rejects PHP health behind metrics that show the task redispatched', () => {
  const observation = model();
  const stage = observation.operator_metrics.stages.waiting_after_redispatch;
  Object.assign(stage.sections.tasks.counts, {dispatch_overdue: 0, unhealthy: 0, max_repair_count: 1});
  Object.assign(stage.sections.tasks.timestamps, {oldest_dispatch_overdue_since: false, oldest_unhealthy_at: false});
  stage.sections.backlog.counts.unhealthy_tasks = 0;
  setChecks(stage, {durable_resume_paths: 'warning'});
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'), /warnings/);
});
test('rejects a repair warning at another stage', () => {
  const observation = model();
  setChecks(observation.operator_metrics.stages.waiting, {durable_resume_paths: 'warning'});
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'), /warnings/);
});
test('rejects a repaired task still reported overdue', () => {
  const observation = model();
  observation.operator_metrics.stages.waiting_after_redispatch.sections.tasks.counts.max_repair_count = 1;
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'));
});
// Published PHP on MariaDB answers health with 500 once an activity exists.
const mariaDbHealthDefect = (observation, runtime, stages) => {
  observation.operator_metrics.database_family = 'mariadb';
  for (const name of stages) {
    Object.assign(observation.operator_metrics.stages[name], {runtime, health_status: 500, health_namespace: null,
      retention: [null, null], health: {status: null, healthy: null, categories: null}, checks: []});
  }
  return observation;
};
const ACTIVITY = ['activity_scheduled', 'activity_running', 'activity_retrying', 'completed'];
test('published PHP health failing on MariaDB after an activity passes', () => assert.deepStrictEqual(
  checkOperatorMetrics(fixture, mariaDbHealthDefect(model(), 'php', ACTIVITY), 'TEST'), projection));
test('rejects native health failing on MariaDB', () => assert.throws(() =>
  checkOperatorMetrics(fixture, mariaDbHealthDefect(model(), 'rust', ACTIVITY), 'TEST')));
test('rejects PHP health failing on MariaDB before any activity', () => assert.throws(() =>
  checkOperatorMetrics(fixture, mariaDbHealthDefect(model(), 'php', ['waiting']), 'TEST')));
test('rejects PHP health failing on another database', () => {
  const observation = mariaDbHealthDefect(model(), 'php', ACTIVITY);
  observation.operator_metrics.database_family = null;
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'));
});
test('rejects an unknown database family', () => {
  const observation = model();
  observation.operator_metrics.database_family = 'oracle';
  assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'));
});
test('embedded explicitly declares the HTTP metrics inapplicable', () => assert.deepStrictEqual(checkOperatorMetrics(fixture,
  {mode: 'embedded', operator_metrics: {applicable: false, reason: 'embedded_has_no_http_operator_metrics'}}, 'test'), projection));
for (const [label, mutate] of [
  ['a missing stage', s => delete s.stages.activity_running],
  ['another namespace', s => s.stages.empty.metrics_namespace = 'default'],
  ['a run never counted', s => s.stages.waiting.sections.runs.counts.total = 0],
  ['a lost waiting run', s => s.stages.activity_scheduled.sections.runs.counts.waiting = 0],
  ['an overdue task not reported', s => s.stages.waiting_after_redispatch.sections.tasks.counts.dispatch_overdue = 0],
  ['an overdue task still delayed', s => Object.assign(s.stages.waiting_after_redispatch.sections.tasks.counts, {ready_due: 0, delayed: 1})],
  ['a retry not counted', s => s.stages.activity_retrying.sections.activities.counts.retrying = 0],
  ['a failed attempt forgotten', s => s.stages.completed.sections.activities.counts.failed_attempts = 0],
  ['a timestamp without its count', s => s.stages.empty.sections.runs.timestamps.oldest_wait_started_at = true],
  ['a count without its timestamp', s => s.stages.activity_retrying.sections.activities.timestamps.oldest_retrying_started_at = false],
  ['a command contract backfill missed', s => s.stages.waiting.sections.command_contracts.counts.actionable_backfill_needed_runs = 0],
  ['a closed run still actionable', s => s.stages.completed.sections.command_contracts.counts.actionable_backfill_needed_runs = 1],
  ['history events miscounted', s => s.stages.completed.sections.history.counts.events = 7],
  ['a worker never counted', s => s.stages.activity_running.active_workers = 0],
  ['a transport warning missed', s => s.stages.waiting_after_redispatch.checks[5].status = 'ok'],
  ['an unexpected warning', s => s.stages.completed.checks[9].status = 'warning'],
  ['a check error', s => s.stages.empty.checks[0].status = 'error'],
  ['a missing check', s => s.stages.empty.checks.pop()],
  ['an unhealthy document', s => s.stages.empty.health.healthy = false],
  ['health answered with 503', s => s.stages.empty.health_status = 503],
  ['other retention', s => s.stages.empty.retention = ['forever', null]],
  ['metrics refused', s => s.stages.empty.metrics_status = 403],
  ['another history budget', s => s.stages.empty.sections.history.counts.fan_out_threshold = 100],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation.operator_metrics);
    assert.throws(() => checkOperatorMetrics(fixture, observation, 'TEST'));
  });
}
