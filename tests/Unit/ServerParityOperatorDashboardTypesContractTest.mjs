import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkOperatorDashboardTypes} from '../../scripts/conformance/server-parity/operator-dashboard-types-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/operator-dashboard-types.json', import.meta.url)));
const definition = fixture.operator_dashboard_types;
const id = 'parity-v1-operator-dashboard-types';
const namespace = `${id}-dash`;
const operatorMetricKeys = ['activities', 'backend', 'backlog', 'capacity_evidence', 'command_contracts', 'generated_at', 'history',
  'history_audit_evaluation', 'matching_role', 'projections', 'repair', 'repair_policy', 'runs', 'runtime_external_payload_cleanup',
  'schedules', 'starts', 'sticky_execution', 'structural_limits', 'tasks', 'update_wait', 'worker_sessions', 'workers'];
const closed = {completed: 0, failed: 0};

// One reduced dashboard, as the probe records it, for the expected runs.
const summary = ({flows, health, max_wait: maxWait}, types) => ({
  status: 200, namespace, keys: [...definition.dashboard_keys], operator_metric_keys: [...operatorMetricKeys],
  flows, flows_past_hour: flows, flows_per_minute: flows / 60, exceptions_past_hour: 0, failed_flows_past_week: 0,
  max_wait_time_workflow: maxWait, max_duration_workflow: null, max_exceptions_workflow: null,
  fleet_overview: {current: {running: flows, failed: 0}, trends: {hour: closed, day: closed, week: closed}},
  workflow_type_health: structuredClone(health), alerts: 0, trend_hours: definition.trend_hours, trend_runs: 0,
  workflow_scope: {namespace, workflow_types: types === undefined ? null : [...types], all_workflow_types: types === undefined},
  operator_metrics_scope: {namespace, workflow_types: null},
});

// The answers a server gives when it matches the fixture. `foldsCase` is a
// database whose collation matches the upper-cased type.
const observation = (foldsCase = false) => {
  const cases = Object.fromEntries(Object.entries(definition.cases).map(([name, expected]) => {
    const figures = expected.collation ? expected.collation[foldsCase ? 'matched' : 'unmatched'] : expected;
    return [name, summary(figures, expected.types)];
  }));
  const refusals = Object.fromEntries(Object.entries(definition.refusals).map(([name, {message}]) =>
    [name, {status: 422, response: {message, errors: {workflow_types: [message]}}}]));
  return {mode: 'http', operator_dashboard_types: {applicable: true, namespace, registered: 201,
    started: definition.starts.map(() => 201), failed: 200,
    unfiltered: summary({flows: 3, max_wait: definition.starts[0],
      health: [[definition.workflow_types[0], 2], [definition.workflow_types[1], 1]]}),
    cases, refusals}};
};
const projection = {scope: 'operator_dashboard_types', cases: 6};

test('the dashboard by workflow type accepts published answers on a case-sensitive database', () => {
  assert.deepStrictEqual(checkOperatorDashboardTypes(fixture, observation(false), id), projection);
});

test('the dashboard by workflow type accepts published answers on a case-folding collation', () => {
  assert.deepStrictEqual(checkOperatorDashboardTypes(fixture, observation(true), id), projection);
});

test('published PHP reports dashboard members in its own order', () => {
  const reordered = observation();
  for (const read of [reordered.operator_dashboard_types.unfiltered, ...Object.values(reordered.operator_dashboard_types.cases)]) {
    read.keys.reverse();
    read.operator_metric_keys.reverse();
  }
  assert.deepStrictEqual(checkOperatorDashboardTypes(fixture, reordered, id), projection);
});

test('embedded applications are explicitly inapplicable with the same projection', () => {
  const embedded = {mode: 'embedded', operator_dashboard_types: {applicable: false, reason: 'embedded_has_no_http_dashboard'}};
  assert.deepStrictEqual(checkOperatorDashboardTypes(fixture, embedded, id), projection);
  assert.throws(() => checkOperatorDashboardTypes(fixture, {mode: 'embedded', operator_dashboard_types: {applicable: true}}, id));
});

const corruptions = {
  'the old 501 refusal': state => { state.cases.orders = {status: 501, response: {reason: 'backend_capability_unavailable'}}; },
  'an unfiltered total': state => { state.cases.orders.flows = 3; },
  'a type left in the health list': state => { state.cases.orders.workflow_type_health.push([definition.workflow_types[1], 1]); },
  'a list that is not echoed': state => { state.cases.repeated.workflow_scope.workflow_types = [definition.workflow_types[0]]; },
  'all types claimed for a list': state => { state.cases.both.workflow_scope.all_workflow_types = true; },
  'an empty list matching runs': state => { Object.assign(state.cases.none, summary({flows: 3, max_wait: definition.starts[0], health: []}, [])); },
  'filtered operator metrics': state => { state.cases.orders.operator_metrics_scope.workflow_types = [definition.workflow_types[0]]; },
  'a missing operator metric': state => { state.cases.orders.operator_metric_keys.pop(); },
  'an unfiltered running count': state => { state.cases.billing_and_missing.fleet_overview.current.running = 3; },
  'a longest wait of another type': state => { state.cases.billing_and_missing.max_wait_time_workflow = definition.workflow_types[0]; },
  'an upper-cased list that matched only part': state => { Object.assign(state.cases.upper, {flows: 1}); },
  'an accepted invalid list': state => { state.refusals.object = {status: 200, response: {}}; },
  'another refusal message': state => { state.refusals.not_json.response.errors.workflow_types = ['The workflow types field is required.']; },
  'a missing case': state => { delete state.cases.both; },
  'a missing dashboard member': state => { state.cases.orders.keys = state.cases.orders.keys.filter(key => key !== 'workflow_scope'); },
};
for (const [name, corrupt] of Object.entries(corruptions)) {
  test(`the dashboard by workflow type refuses ${name}`, () => {
    const corrupted = observation();
    corrupt(corrupted.operator_dashboard_types);
    assert.throws(() => checkOperatorDashboardTypes(fixture, corrupted, id));
  });
}
