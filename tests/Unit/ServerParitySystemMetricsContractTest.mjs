import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkSystemMetrics} from '../../scripts/conformance/server-parity/system-metrics-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/system-metrics.json', import.meta.url)));
const definition = fixture.system_metrics;
const id = 'parity-v1-system-metrics';
const namespace = `${id}-metrics`;
const resources = Object.keys(definition.usage);
const zeros = keys => Object.fromEntries(keys.map(key => [key, 0]));
const nulls = keys => Object.fromEntries(keys.map(key => [key, null]));

// A response as published Server 2.11.1 answered the scenario, with the
// timeline drift PHP's asynchronous projection showed.
function response(scenario, timelineDrift = 0) {
  const failures = scenario ? definition.failures : {...definition.failures, max_consecutive_failures: 0,
    failed_task_count: 0, workflow_type_count: 0, by_workflow_type: []};
  const counts = definition.projection_tables.map(table => table === 'run_timeline_entries' ? timelineDrift : 0);
  const metrics = {
    dw_workflow_task_consecutive_failures: failures,
    dw_projection_drift_total: {
      total: timelineDrift, table_count: 5, tables_with_drift: timelineDrift > 0 ? 1 : 0, scope: 'server',
      label_cardinality_policy: {namespace: 'server_scope_no_label', table: {cardinality_class: 'finite_projection_table_inventory',
        values: definition.projection_tables, selection: 'fixed_projection_table_inventory'}},
      by_table: definition.projection_tables.map((table, index) => ({table, needs_rebuild: counts[index]})),
    },
    dw_namespace_request_admission_rejections: {
      max_requests_per_minute: null, max_concurrent_requests: null, requests_this_minute: 0, rejections_this_minute: 0,
      rejections_by_reason: zeros(['namespace_request_rate_exhausted', 'namespace_request_concurrency_exhausted',
        'namespace_request_admission_unavailable']),
      configuration_status: 'valid',
      label_cardinality_policy: {namespace: 'request_scope_not_label', reason: 'finite_three_reason_inventory'},
    },
    dw_namespace_durable_state_usage: {
      limits: nulls(resources), usage: scenario ? definition.usage : zeros(resources), remaining: nulls(resources),
      rejections_this_minute: 0,
      rejections_by_reason: zeros([...resources.map(resource => `namespace_${resource}_exhausted`), 'namespace_durable_state_quota_unavailable']),
      configuration_status: 'valid', measurement_status: 'available',
      label_cardinality_policy: {namespace: 'request_scope_not_label', reason: 'finite_reason_inventory'},
    },
    dw_runtime_external_payload_namespace_usage: {
      max_bytes: null, max_objects: null, used_bytes: 0, used_objects: 0, remaining_bytes: null, remaining_objects: null,
      rejections_this_minute: 0, rejections_by_reason: zeros(['external_payload_namespace_bytes_exhausted',
        'external_payload_namespace_objects_exhausted', 'external_payload_namespace_quota_unavailable']),
      configuration_status: 'valid', measurement_status: 'available',
      label_cardinality_policy: {namespace: 'request_scope_not_label', reason: 'finite_three_reason_inventory'},
    },
  };
  const cardinality = {metric_label_sets: Object.fromEntries(Object.entries(metrics)
    .map(([name, family]) => [name, family.label_cardinality_policy]))};
  return structuredClone({status: 200, response: {generated_at: '2026-10-22T22:15:23.466270Z', namespace, metrics, cardinality}});
}

const observation = (timelineDrift = 1) => ({mode: 'http', system_metrics: {
  applicable: true, namespace, empty: response(false), registered: 201, started: [201, 201],
  claimed_type: definition.workflow_types[0], failed: 200, scheduled: 201,
  read: response(true, timelineDrift), posted: response(true, timelineDrift),
}});

test('the system metrics accept the published answers', () => {
  assert.deepStrictEqual(checkSystemMetrics(fixture, observation(), id), {scope: 'system_metrics', resources: 16});
});

test('the system metrics accept native answers with no drift and millisecond times', () => {
  const value = observation(0);
  value.system_metrics.read.response.generated_at = '2026-10-22T22:15:23.466Z';
  checkSystemMetrics(fixture, value, id);
});

test('the fixture pins the scenario counts', () => {
  assert.deepStrictEqual(resources.length, 16);
  assert.deepStrictEqual([definition.usage.workflow_tasks, definition.usage.pending_workflow_tasks, definition.usage.workflow_commands], [3, 2, 2]);
  assert.deepStrictEqual(definition.failures.by_workflow_type, [{workflow_type: 'parity.v1.metrics_a', max_consecutive_failures: 1, failed_task_count: 1}]);
});

test('the embedded system metrics are explicitly inapplicable', () => {
  checkSystemMetrics(fixture, {mode: 'embedded', system_metrics: {applicable: false, reason: 'embedded_has_no_http_metrics'}}, id);
  assert.throws(() => checkSystemMetrics(fixture, {mode: 'embedded', system_metrics: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['an unimplemented route', state => { state.read = {status: 501, response: {reason: 'rust_capability_not_implemented'}}; }],
  ['a refused POST', state => { state.posted.status = 405; }],
  ['another namespace', state => { state.read.response.namespace = 'default'; }],
  ['a wrong durable-state count', state => { state.read.response.metrics.dw_namespace_durable_state_usage.usage.workflow_tasks = 2; }],
  ['a configured limit', state => { state.read.response.metrics.dw_namespace_durable_state_usage.limits.schedules = 10; }],
  ['a missing quota reason', state => { delete state.read.response.metrics.dw_namespace_durable_state_usage.rejections_by_reason.namespace_durable_state_quota_unavailable; }],
  ['the failure on the other type', state => { state.read.response.metrics.dw_workflow_task_consecutive_failures.by_workflow_type[0] = {workflow_type: 'parity.v1.metrics_b', max_consecutive_failures: 1, failed_task_count: 1}; }],
  ['a failure counted before any attempt', state => { state.empty.response.metrics.dw_workflow_task_consecutive_failures.failed_task_count = 1; }],
  ['a stored external payload', state => { state.read.response.metrics.dw_runtime_external_payload_namespace_usage.used_objects = 1; }],
  ['a drift total unlike its tables', state => { state.read.response.metrics.dw_projection_drift_total.total = 0; }],
  ['a negative drift', state => { state.read.response.metrics.dw_projection_drift_total.by_table[0].needs_rebuild = -1; }],
  ['a missing drift table', state => { state.read.response.metrics.dw_projection_drift_total.by_table.pop(); }],
  ['a namespace-scoped drift', state => { state.read.response.metrics.dw_projection_drift_total.scope = 'namespace'; }],
  ['an extra metric family', state => { state.read.response.metrics.dw_extra = {label_cardinality_policy: {}}; }],
  ['a cardinality summary unlike its families', state => { state.read.response.cardinality.metric_label_sets.dw_projection_drift_total = {}; }],
  ['an extra top-level field', state => { state.read.response.control_plane = {}; }],
  ['a local time', state => { state.read.response.generated_at = '2026-10-22 22:15:23'; }],
  ['the second type claimed first', state => { state.claimed_type = definition.workflow_types[1]; }],
]) {
  test(`the system metrics reject ${label}`, () => {
    const value = observation();
    mutate(value.system_metrics);
    assert.throws(() => checkSystemMetrics(fixture, value, id));
  });
}
