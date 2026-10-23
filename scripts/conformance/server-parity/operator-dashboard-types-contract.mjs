import assert from 'node:assert/strict';

// One dashboard reduced by operator-dashboard-types-probe.php, checked for the
// expected runs. Every run is open, so the closed and failure figures are zero.
function checkDashboard(definition, read, namespace, label, {flows, health, max_wait: maxWait}) {
  assert.equal(read.status, 200, `${label} dashboard answered`);
  assert.equal(read.namespace, namespace, `${label} namespace`);
  assert.deepStrictEqual([...read.keys].sort(), definition.dashboard_keys, `${label} dashboard members, in any order`);
  assert.equal(read.flows, flows, `${label} flows`);
  assert.equal(read.flows_past_hour, flows, `${label} flows in the last hour`);
  assert.ok(Math.abs(read.flows_per_minute - flows / 60) < 1e-9, `${label} flows per minute`);
  assert.equal(read.exceptions_past_hour, 0, `${label} exceptions`);
  assert.equal(read.failed_flows_past_week, 0, `${label} failed flows`);
  assert.equal(read.max_wait_time_workflow, maxWait, `${label} longest wait`);
  assert.equal(read.max_duration_workflow, null, `${label} longest duration`);
  assert.equal(read.max_exceptions_workflow, null, `${label} most exceptions`);
  const closed = {completed: 0, failed: 0};
  assert.deepStrictEqual(read.fleet_overview, {current: {running: flows, failed: 0},
    trends: {hour: closed, day: closed, week: closed}}, `${label} fleet overview`);
  assert.deepStrictEqual(read.workflow_type_health, health, `${label} workflow type health`);
  assert.equal(read.alerts, 0, `${label} attention alerts`);
  assert.equal(read.trend_hours, definition.trend_hours, `${label} trend hours`);
  assert.equal(read.trend_runs, 0, `${label} closed runs in the trends`);
  assert.deepStrictEqual(read.operator_metrics_scope, {namespace, workflow_types: null}, `${label} operator metrics keep the namespace`);
}

// Reviewed `workflowTypeOperatorDashboard` (published Server 2.11.1): the
// bounded dashboard with its run figures narrowed to the listed types.
export function checkOperatorDashboardTypes(fixture, observation, workflowId) {
  const definition = fixture.operator_dashboard_types;
  const state = observation.operator_dashboard_types;
  // The same for every target: embedded applications have no HTTP dashboard.
  const projection = {scope: 'operator_dashboard_types', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual dashboard by workflow type observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_dashboard'}, 'embedded dashboard explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-dash`.toLowerCase();
  assert.equal(state.namespace, namespace, 'fresh namespace');
  assert.equal(state.registered, 201, 'worker registered');
  assert.deepStrictEqual(state.started, definition.starts.map(() => 201), 'every run started');
  assert.equal(state.failed, 200, 'one workflow task failed');
  const all = {flows: definition.starts.length, max_wait: definition.starts[0],
    health: [[definition.workflow_types[0], 2], [definition.workflow_types[1], 1]]};
  checkDashboard(definition, state.unfiltered, namespace, 'unfiltered', all);
  assert.deepStrictEqual(state.unfiltered.workflow_scope, {namespace, workflow_types: null, all_workflow_types: true}, 'unfiltered scope');
  assert.deepStrictEqual(Object.keys(state.cases), Object.keys(definition.cases), 'every case read');
  for (const [name, expected] of Object.entries(definition.cases)) {
    const read = state.cases[name];
    let figures = expected;
    if (expected.collation) {
      // The database decides whether an upper-cased type matches.
      figures = read.flows === 0 ? expected.collation.unmatched : expected.collation.matched;
    }
    checkDashboard(definition, read, namespace, name, figures);
    assert.deepStrictEqual(read.workflow_scope, {namespace, workflow_types: expected.types, all_workflow_types: false},
      `${name} scope echoes the list`);
    assert.deepStrictEqual([...read.operator_metric_keys].sort(), [...state.unfiltered.operator_metric_keys].sort(), `${name} operator metrics`);
  }
  assert.deepStrictEqual(Object.keys(state.refusals), Object.keys(definition.refusals), 'every refusal sent');
  for (const [name, {message}] of Object.entries(definition.refusals)) {
    const refusal = state.refusals[name];
    assert.equal(refusal.status, 422, `${name} refused`);
    assert.deepStrictEqual(refusal.response.errors, {workflow_types: [message]}, `${name} refusal message`);
  }

  return projection;
}
