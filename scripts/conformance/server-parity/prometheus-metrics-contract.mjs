import assert from 'node:assert/strict';

const MICROS = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;
const BOUNDS = ['0.1', '0.5', '1', '2.5', '5', '10', '30', '60', '300', '900'];
const LIMIT = 100;
const SELECTIONS = {
  workflows: 'bounded_task_queue_and_workflow_type_ascending',
  activities: 'bounded_task_queue_workflow_type_and_activity_type_ascending',
  task_queues: 'task_queue_name_ascending',
};
const DIMENSIONS = {
  workflows: ['task_queue', 'workflow_type'],
  activities: ['task_queue', 'workflow_type', 'activity_type'],
  task_queues: ['task_queue'],
};

const zeros = keys => Object.fromEntries(keys.map(key => [key, 0]));

function labelSet(family) {
  return {namespace: 'request_scope_not_label', ...Object.fromEntries(DIMENSIONS[family]
    .map(dimension => [dimension, {cardinality_class: 'bounded_series', limit: LIMIT, selection: SELECTIONS[family]}]))};
}

function cardinality(counts) {
  const disclosure = family => ({
    label_dimensions: DIMENSIONS[family], limit: LIMIT, observed_series_count: counts[family],
    observed_series_count_precision: 'exact', reported_series_count: counts[family], suppressed_series_count: 0,
    suppressed_started_total: 0, suppressed_counts_precision: 'exact', truncated: false, selection: SELECTIONS[family],
  });
  return {
    series_limits: {workflows: disclosure('workflows'), activities: disclosure('activities'), task_queues: disclosure('task_queues')},
    metric_label_sets: {
      dw_workflow_runs_total: labelSet('workflows'), dw_workflow_run_latency_seconds: labelSet('workflows'),
      dw_activity_executions_total: labelSet('activities'), dw_activity_execution_latency_seconds: labelSet('activities'),
      dw_task_queue_runtime_state: labelSet('task_queues'),
    },
  };
}

// A latency histogram for durations that each last at least `minimum`
// seconds. Its bounds are cumulative. Published PHP on SQLite compares each
// duration with a bound bound as text, which SQLite orders after every number,
// so every bucket holds every duration; that recorded reference defect is
// accepted beside the cumulative histogram (see the fixture's contract).
function checkLatency(latency, count, minimum, label) {
  assert.deepStrictEqual(Object.keys(latency).sort(), ['buckets', 'count', 'sum'], `${label} latency fields`);
  assert.equal(latency.count, count, `${label} latency count`);
  assert.deepStrictEqual(Object.keys(latency.buckets).sort(), [...BOUNDS].sort(), `${label} bucket bounds`);
  const buckets = BOUNDS.map(bound => latency.buckets[bound]);
  assert.ok(buckets.every(Number.isInteger), `${label} integer buckets`);
  if (count === 0) {
    assert.deepStrictEqual([latency.sum, buckets], [0, BOUNDS.map(() => 0)], `${label} empty histogram`);
    return;
  }
  assert.ok(typeof latency.sum === 'number' && latency.sum >= count * minimum, `${label} latency sum covers each duration`);
  assert.ok(buckets.every((value, index) => index === 0 || value >= buckets[index - 1]), `${label} cumulative buckets`);
  assert.equal(buckets.at(-1), count, `${label} every duration within 900 seconds`);
  const cumulative = BOUNDS.filter(bound => Number(bound) < minimum).every(bound => latency.buckets[bound] === 0);
  const textBounds = buckets.every(value => value === count);
  assert.ok(cumulative || textBounds, `${label} buckets below the minimum duration are empty`);
}

function queueEntry(queue, values = {}) {
  const fields = [];
  for (const prefix of ['workflow', 'activity']) {
    for (const field of ['ready_tasks', 'ready_due_tasks', 'delayed_tasks', 'leased_tasks', 'expired_leases',
      'tasks_added_last_minute', 'tasks_dispatched_last_minute']) {
      fields.push(`${prefix}_${field}`);
    }
  }
  fields.push('active_pollers', 'workflow_slot_capacity', 'activity_slot_capacity', 'available_workflow_slots',
    'available_activity_slots');
  return {task_queue: queue, ...zeros(fields), ...values};
}

function checkSummary(recorded, namespace, expected, label) {
  assert.equal(recorded.status, 200, `${label} status`);
  const body = recorded.response;
  assert.deepStrictEqual(Object.keys(body).sort(), ['cardinality', 'generated_at', 'latency_buckets_seconds', 'namespace',
    'observed_series_count', 'observed_series_count_precision', 'schema', 'series', 'series_count',
    'suppressed_series_count', 'suppressed_series_count_precision'], `${label} fields`);
  assert.match(body.generated_at, MICROS, `${label} generated_at`);
  const counts = {workflows: expected.workflows.length, activities: expected.activities.length, task_queues: expected.task_queues.length};
  const total = counts.workflows + counts.activities + counts.task_queues;
  assert.deepStrictEqual({...body, generated_at: null, series: null},
    {schema: 'durable-workflow.prometheus-metrics.v1', generated_at: null, namespace,
      latency_buckets_seconds: BOUNDS.map(Number), series: null, series_count: total, observed_series_count: total,
      observed_series_count_precision: 'exact', suppressed_series_count: 0, suppressed_series_count_precision: 'exact',
      cardinality: cardinality(counts)}, `${label} summary`);
  assert.deepStrictEqual(Object.keys(body.series).sort(), ['activities', 'task_queues', 'workflows'], `${label} families`);
  for (const family of ['workflows', 'activities']) {
    assert.equal(body.series[family].length, expected[family].length, `${label} ${family} series`);
    body.series[family].forEach((series, index) => {
      const {latency_seconds: latency, ...counters} = series;
      const {latency_count: count, ...wanted} = expected[family][index];
      assert.deepStrictEqual(counters, wanted, `${label} ${family}[${index}]`);
      checkLatency(latency, count, expected.minimum, `${label} ${family}[${index}]`);
    });
  }
  assert.deepStrictEqual(body.series.task_queues, expected.task_queues, `${label} task queues`);
}

// Reviewed Prometheus summary (SystemController::prometheusMetrics). Counters,
// task-queue state, worker capacity and the bounded-series disclosure are
// exact; latency sums and buckets depend on elapsed time, so the contract
// checks their bounds instead.
export function checkPrometheusMetrics(fixture, observation, workflowId) {
  const definition = fixture.prometheus_metrics;
  const state = observation.prometheus_metrics;
  const projection = {scope: 'prometheus_metrics', series: 6};
  assert.ok(state && typeof state === 'object', 'actual Prometheus metrics observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_metrics'}, 'embedded metrics explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-prometheus`.toLowerCase();
  const [first, second] = [`${namespace}-q1`, `${namespace}-q2`];
  assert.deepStrictEqual([state.applicable, state.namespace, state.queues], [true, namespace, [first, second]], 'scenario identity');
  assert.deepStrictEqual(state.statuses, definition.statuses, 'scenario requests');
  const minimum = definition.minimum_duration_ms / 1000;
  checkSummary(state.empty, namespace, {workflows: [], activities: [], task_queues: [], minimum}, 'empty namespace');
  const [typeA, typeB] = definition.workflow_types;
  const run = (queue, type, values) => ({task_queue: queue, workflow_type: type, started_total: 1, completed_total: 0,
    failed_total: 0, cancelled_total: 0, terminated_total: 0, running: 0, latency_count: 0, ...values});
  checkSummary(state.read, namespace, {
    minimum,
    workflows: [
      run(first, typeA, {completed_total: 1, latency_count: 1}),
      run(first, typeB, {terminated_total: 1, latency_count: 1}),
      run(second, typeA, {running: 1}),
    ],
    activities: [{task_queue: first, workflow_type: typeA, activity_type: definition.activity_type, started_total: 1,
      completed_total: 1, failed_total: 0, cancelled_total: 0, running: 0, latency_count: 1}],
    task_queues: [
      queueEntry(first, {...definition.first_queue, active_pollers: 1, workflow_slot_capacity: definition.workflow_slots,
        activity_slot_capacity: definition.activity_slots, available_workflow_slots: definition.workflow_slots,
        available_activity_slots: definition.activity_slots}),
      queueEntry(second, definition.second_queue),
    ],
  }, 'after the scenario');
  return projection;
}
