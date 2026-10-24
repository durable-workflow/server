import assert from 'node:assert/strict';
import {isDeepStrictEqual} from 'node:util';

// Reviewed operator metrics and health of one namespace, as published Server
// 2.11.1 reports them through a run's stages. Counts must match exactly, with
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
// Past the redispatch window, published PHP's maintenance loop moves the
// overdue ready task through four states:
//   0 overdue;
//   1 repairing: TaskWatchdog::recoverExistingTask sets the repair count and
//     projects the run as needing repair, in one transaction;
//   2 dispatched: TaskDispatcher::markDispatched saves the task as dispatched,
//     and its run is not yet projected again;
//   3 redispatched: the run is projected again.
// The task is overdue in states 0 and 1, carries a repair count from state 1,
// and its run reads as needing repair in states 1 and 2.
// Workflow aca7928484's OperatorMetrics::snapshot reads each value with its
// own query, so one document can see several states, never going backwards
// in query order. Its reads that depend on the state, in that order:
//   runMetrics: oldest_repair_needed_at, then repair_needed;
//   taskMetrics: oldest_dispatch_overdue_since (oldest_unhealthy_at is built
//     from the same timestamps), then dispatch_overdue, then
//     max_repair_count, then unhealthy, counted again;
//   backlogMetrics: unhealthy_tasks, then repair_needed_runs.
// The health request reads its own snapshot afterwards, in the same order:
// task_transport warns on tasks.unhealthy, routing_health on
// tasks.dispatch_overdue, and durable_resume_paths on
// backlog.repair_needed_runs. Native runs no repair loop: it keeps the task
// overdue, or shows it redispatched once repaired.
const PHP_REPAIR_STATES = [0, 1, 2, 3];
const PHP_READS = 8;
const overdueAt = state => state <= 1;
const runNeedsRepairAt = state => state === 1 || state === 2;
const runsAt = state => (runNeedsRepairAt(state) ? {...waitingRun, repair_needed: 1} : waitingRun);
const backlogAt = (unhealthy, repairNeeded) => ({runnable_or_delayed_tasks: 1, tasks_added_last_minute: 1,
  ...(overdueAt(unhealthy) ? {unhealthy_tasks: 1} : {}), ...(runNeedsRepairAt(repairNeeded) ? {repair_needed_runs: 1} : {})});
const warningsAt = (task, run) => ['command_contract_snapshots',
  ...(overdueAt(task) ? ['task_transport', 'routing_health'] : []), ...(runNeedsRepairAt(run) ? ['durable_resume_paths'] : [])];
const REDISPATCHED = {...EXPECTED.waiting_after_redispatch, runs: runsAt(3),
  tasks: {open: 1, ready: 1, ready_due_or_delayed: 1}, backlog: backlogAt(3, 3)};
// The timestamps a PHP read may take apart from their counts.
const PHP_STAMPED = {runs: ['oldest_repair_needed_at'], tasks: ['oldest_dispatch_overdue_since', 'oldest_unhealthy_at']};

// Every way `count` reads can see the states without going backwards.
function forwardReads(count, from = 0) {
  if (count === 0) return [[]];
  return PHP_REPAIR_STATES.filter(state => state >= from)
    .flatMap(state => forwardReads(count - 1, state).map(rest => [state, ...rest]));
}
const PHP_DOCUMENT_READS = forwardReads(PHP_READS);

// What a PHP document whose reads saw these states reports.
function phpDocument([runStamp, runCount, taskStamp, overdue, repairs, unhealthy, backlogUnhealthy, backlogRepair]) {
  return {
    counts: {
      runs: runsAt(runCount),
      tasks: {open: 1, ready: 1, ready_due_or_delayed: 1, ...(overdueAt(overdue) ? {dispatch_overdue: 1} : {}),
        ...(repairs >= 1 ? {max_repair_count: 1} : {}), ...(overdueAt(unhealthy) ? {unhealthy: 1} : {})},
      backlog: backlogAt(backlogUnhealthy, backlogRepair),
    },
    timestamps: {
      runs: {oldest_repair_needed_at: runNeedsRepairAt(runStamp)},
      tasks: {oldest_dispatch_overdue_since: overdueAt(taskStamp), oldest_unhealthy_at: overdueAt(taskStamp)},
    },
  };
}

// The warnings of a PHP health request whose reads saw these states.
const phpWarnings = reads => ['command_contract_snapshots', ...(overdueAt(reads[5]) ? ['task_transport'] : []),
  ...(overdueAt(reads[3]) ? ['routing_health'] : []), ...(runNeedsRepairAt(reads[7]) ? ['durable_resume_paths'] : [])];

const nonzero = values => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== 0));

// Published PHP past the redispatch window: the expected counts, timestamps
// and the warnings its health may report. `timestamps` holds the presence of
// the PHP_STAMPED fields. The document's reads take the states that match
// and end earliest; the health request's reads start from there. With no
// match, the reads that match the counts alone are expected, so a timestamp
// difference is reported; with none either, the overdue state is expected.
export function phpRedispatchExpectation(counts, timestamps) {
  const base = EXPECTED.waiting_after_redispatch;
  const seen = {runs: nonzero(counts.runs), tasks: nonzero(counts.tasks), backlog: nonzero(counts.backlog)};
  const earliest = matches => matches.reduce((best, reads) => (best === null || reads[PHP_READS - 1] < best[PHP_READS - 1] ? reads : best), null);
  const countMatches = PHP_DOCUMENT_READS.filter(reads => isDeepStrictEqual(phpDocument(reads).counts, seen));
  const best = earliest(countMatches.filter(reads => isDeepStrictEqual(phpDocument(reads).timestamps, timestamps)))
    ?? earliest(countMatches);
  if (best === null) {
    const overdue = phpDocument(Array(PHP_READS).fill(0));
    return {counts: {...base, ...overdue.counts}, timestamps: overdue.timestamps, warnings: [WARNINGS.waiting_after_redispatch]};
  }
  const document = phpDocument(best);
  const warnings = [];
  for (const reads of forwardReads(PHP_READS, best[PHP_READS - 1])) {
    const list = phpWarnings(reads);
    if (!warnings.some(listed => isDeepStrictEqual(listed, list))) warnings.push(list);
  }
  return {counts: {...base, ...document.counts}, timestamps: document.timestamps, warnings};
}

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
  const phpRedispatch = name === 'waiting_after_redispatch' && stage.runtime === 'php';
  for (const [section, pairs] of Object.entries(TIMESTAMPS)) {
    for (const [field, count] of Object.entries(pairs)) {
      // PHP's repair loop may move between a timestamp's query and its
      // count's; the redispatch model checks those timestamps.
      if (phpRedispatch && PHP_STAMPED[section]?.includes(field)) continue;
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
  let expected = {counts: EXPECTED[name], warnings: [WARNINGS[name]]};
  let stamps = null;
  if (phpRedispatch) {
    stamps = Object.fromEntries(Object.entries(PHP_STAMPED).map(([section, fields]) =>
      [section, Object.fromEntries(fields.map(field => [field, raw[section].timestamps[field]]))]));
    expected = phpRedispatchExpectation(counts, stamps);
  } else if (redispatch && counts.tasks.max_repair_count > 0) {
    delete counts.tasks.max_repair_count;
    expected = {counts: REDISPATCHED, warnings: [warningsAt(3, 3)]};
  }
  for (const [section, values] of Object.entries(counts)) {
    assert.deepStrictEqual(nonzero(values), expected.counts[section] ?? {}, `${label} ${section} counts`);
  }
  if (stamps !== null) assert.deepStrictEqual(stamps, expected.timestamps, `${label} repair timestamps`);
  assert.equal(stage.active_workers, ['empty', 'waiting', 'waiting_after_redispatch'].includes(name) ? 0 : 1,
    `${label} active workers`);
  if (defect) return counts;
  assert.deepStrictEqual(stage.checks.map(check => [check.name, check.category]), CHECKS, `${label} check names`);
  const statuses = stage.checks.filter(check => check.name !== UNCOMPARED_CHECK);
  assert.ok(statuses.every(check => ['ok', 'warning'].includes(check.status)), `${label} no check errors`);
  const warnings = statuses.filter(check => check.status !== 'ok').map(check => check.name);
  const settled = expected.warnings.find(list => isDeepStrictEqual(list, warnings)) ?? expected.warnings[0];
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
