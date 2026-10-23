import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkPrometheusMetrics} from '../../scripts/conformance/server-parity/prometheus-metrics-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/prometheus-metrics.json', import.meta.url)));
const definition = fixture.prometheus_metrics;
const id = 'parity-v1-prometheus-metrics';
const namespace = `${id}-prometheus`;
const [first, second] = [`${namespace}-q1`, `${namespace}-q2`];
const [typeA, typeB] = definition.workflow_types;
const bounds = [0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 300, 900];
const selection = {
  workflows: 'bounded_task_queue_and_workflow_type_ascending',
  activities: 'bounded_task_queue_workflow_type_and_activity_type_ascending',
  task_queues: 'task_queue_name_ascending',
};
const dimensions = {workflows: ['task_queue', 'workflow_type'], activities: ['task_queue', 'workflow_type', 'activity_type'],
  task_queues: ['task_queue']};

// A histogram of durations, cumulative or with PHP-on-SQLite's text bounds.
function latency(durations, textBounds = false) {
  return {count: durations.length, sum: durations.reduce((sum, value) => sum + value, 0),
    buckets: Object.fromEntries(bounds.map(bound => [String(bound),
      textBounds ? durations.length : durations.filter(value => value <= bound).length]))};
}

function queue(name, values = {}) {
  const entry = {task_queue: name};
  for (const prefix of ['workflow', 'activity']) {
    for (const field of ['ready_tasks', 'ready_due_tasks', 'delayed_tasks', 'leased_tasks', 'expired_leases',
      'tasks_added_last_minute', 'tasks_dispatched_last_minute']) {
      entry[`${prefix}_${field}`] = 0;
    }
  }
  for (const field of ['active_pollers', 'workflow_slot_capacity', 'activity_slot_capacity', 'available_workflow_slots',
    'available_activity_slots']) {
    entry[field] = 0;
  }
  return {...entry, ...values};
}

function summary(series) {
  const counts = Object.fromEntries(Object.entries(series).map(([family, list]) => [family, list.length]));
  const total = counts.workflows + counts.activities + counts.task_queues;
  const labels = family => ({namespace: 'request_scope_not_label', ...Object.fromEntries(dimensions[family]
    .map(dimension => [dimension, {cardinality_class: 'bounded_series', limit: 100, selection: selection[family]}]))});
  const disclosure = family => ({label_dimensions: dimensions[family], limit: 100, observed_series_count: counts[family],
    observed_series_count_precision: 'exact', reported_series_count: counts[family], suppressed_series_count: 0,
    suppressed_started_total: 0, suppressed_counts_precision: 'exact', truncated: false, selection: selection[family]});
  return {status: 200, response: {schema: 'durable-workflow.prometheus-metrics.v1', generated_at: '2026-10-22T22:29:44.981017Z',
    namespace, latency_buckets_seconds: bounds, series, series_count: total, observed_series_count: total,
    observed_series_count_precision: 'exact', suppressed_series_count: 0, suppressed_series_count_precision: 'exact',
    cardinality: {series_limits: {workflows: disclosure('workflows'), activities: disclosure('activities'),
      task_queues: disclosure('task_queues')}, metric_label_sets: {dw_workflow_runs_total: labels('workflows'),
      dw_workflow_run_latency_seconds: labels('workflows'), dw_activity_executions_total: labels('activities'),
      dw_activity_execution_latency_seconds: labels('activities'), dw_task_queue_runtime_state: labels('task_queues')}}}};
}

function observation(textBounds = false) {
  const run = (queueName, type, values, durations) => ({task_queue: queueName, workflow_type: type, started_total: 1,
    completed_total: 0, failed_total: 0, cancelled_total: 0, terminated_total: 0, running: 0, ...values,
    latency_seconds: latency(durations, textBounds)});
  const read = summary({
    workflows: [run(first, typeA, {completed_total: 1}, [2.673]), run(first, typeB, {terminated_total: 1}, [1.161]),
      run(second, typeA, {running: 1}, [])],
    activities: [{task_queue: first, workflow_type: typeA, activity_type: definition.activity_type, started_total: 1,
      completed_total: 1, failed_total: 0, cancelled_total: 0, running: 0, latency_seconds: latency([1.102992], textBounds)}],
    task_queues: [queue(first, {...definition.first_queue, active_pollers: 1, workflow_slot_capacity: 4, activity_slot_capacity: 3,
      available_workflow_slots: 4, available_activity_slots: 3}), queue(second, definition.second_queue)],
  });
  return structuredClone({mode: 'http', prometheus_metrics: {applicable: true, namespace, queues: [first, second],
    statuses: definition.statuses, empty: summary({workflows: [], activities: [], task_queues: []}), read}});
}

test('the Prometheus summary accepts the cumulative histogram', () => {
  assert.deepStrictEqual(checkPrometheusMetrics(fixture, observation(), id), {scope: 'prometheus_metrics', series: 6});
});

test("the Prometheus summary records PHP on SQLite's text-bound buckets", () => {
  checkPrometheusMetrics(fixture, observation(true), id);
});

test('the embedded Prometheus summary is explicitly inapplicable', () => {
  checkPrometheusMetrics(fixture, {mode: 'embedded', prometheus_metrics: {applicable: false, reason: 'embedded_has_no_http_metrics'}}, id);
  assert.throws(() => checkPrometheusMetrics(fixture, {mode: 'embedded', prometheus_metrics: {applicable: true}}, id));
});

const read = state => state.read.response;
for (const [label, mutate] of [
  ['an unimplemented route', state => { state.read = {status: 501, response: {reason: 'rust_capability_not_implemented'}}; }],
  ['a refused scenario request', state => { state.statuses.terminate = 422; }],
  ['another namespace', state => { read(state).namespace = 'default'; }],
  ['a missing series', state => { read(state).series.workflows.pop(); }],
  ['series out of order', state => { read(state).series.workflows.reverse(); }],
  ['a terminated run counted as cancelled', state => { Object.assign(read(state).series.workflows[1], {terminated_total: 0, cancelled_total: 1}); }],
  ['a waiting run not running', state => { read(state).series.workflows[2].running = 0; }],
  ['a duration for a waiting run', state => { read(state).series.workflows[2].latency_seconds.count = 1; }],
  ['a duration in a bucket below its length', state => { read(state).series.workflows[0].latency_seconds.buckets['0.5'] = 1; }],
  ['non-cumulative buckets', state => { read(state).series.workflows[0].latency_seconds.buckets['900'] = 0; }],
  ['a sum below the minimum duration', state => { read(state).series.activities[0].latency_seconds.sum = 0.2; }],
  ['a missing bucket', state => { delete read(state).series.activities[0].latency_seconds.buckets['300']; }],
  ['a delayed due task', state => { Object.assign(read(state).series.task_queues[1], {workflow_ready_due_tasks: 0, workflow_delayed_tasks: 1}); }],
  ['missing worker capacity', state => { read(state).series.task_queues[0].workflow_slot_capacity = 0; }],
  ['a worker counted on the second queue', state => { read(state).series.task_queues[1].active_pollers = 1; }],
  ['an undisclosed truncation', state => { read(state).cardinality.series_limits.workflows.truncated = true; }],
  ['another series limit', state => { read(state).cardinality.metric_label_sets.dw_workflow_runs_total.task_queue.limit = 50; }],
  ['a millisecond time', state => { read(state).generated_at = '2026-10-22T22:29:44.981Z'; }],
  ['an extra field', state => { read(state).control_plane = {}; }],
]) {
  test(`the Prometheus summary rejects ${label}`, () => {
    const value = observation();
    mutate(value.prometheus_metrics);
    assert.throws(() => checkPrometheusMetrics(fixture, value, id));
  });
}
