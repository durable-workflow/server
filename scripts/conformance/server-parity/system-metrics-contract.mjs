import assert from 'node:assert/strict';

const MICROS_OR_MILLIS = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}(\d{3})?Z$/;

function nulls(keys) {
  return Object.fromEntries(keys.map(key => [key, null]));
}

function zeros(keys) {
  return Object.fromEntries(keys.map(key => [key, 0]));
}

// Reviewed namespace metrics (SystemController::metrics). Namespace-scoped
// values are exact. Projection drift is server-wide and counts rows other
// fixtures leave stale or orphaned, so only its shape and totals are checked.
export function checkSystemMetrics(fixture, observation, workflowId) {
  const definition = fixture.system_metrics;
  const state = observation.system_metrics;
  const projection = {scope: 'system_metrics', resources: Object.keys(definition.usage).length};
  assert.ok(state && typeof state === 'object', 'actual system metrics observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_metrics'}, 'embedded metrics explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-metrics`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace, state.registered, state.started, state.claimed_type, state.failed, state.scheduled],
    [true, namespace, 201, [201, 201], definition.workflow_types[0], 200, 201], 'metrics scenario');
  const resources = Object.keys(definition.usage);
  const durableReasons = [...resources.map(resource => `namespace_${resource}_exhausted`), 'namespace_durable_state_quota_unavailable'];
  const expected = (failures, usage) => ({
    dw_workflow_task_consecutive_failures: failures,
    dw_namespace_request_admission_rejections: {
      max_requests_per_minute: null, max_concurrent_requests: null, requests_this_minute: 0, rejections_this_minute: 0,
      rejections_by_reason: zeros(['namespace_request_rate_exhausted', 'namespace_request_concurrency_exhausted',
        'namespace_request_admission_unavailable']),
      configuration_status: 'valid',
      label_cardinality_policy: {namespace: 'request_scope_not_label', reason: 'finite_three_reason_inventory'},
    },
    dw_namespace_durable_state_usage: {
      limits: nulls(resources), usage, remaining: nulls(resources), rejections_this_minute: 0,
      rejections_by_reason: zeros(durableReasons), configuration_status: 'valid', measurement_status: 'available',
      label_cardinality_policy: {namespace: 'request_scope_not_label', reason: 'finite_reason_inventory'},
    },
    dw_runtime_external_payload_namespace_usage: {
      max_bytes: null, max_objects: null, used_bytes: 0, used_objects: 0, remaining_bytes: null, remaining_objects: null,
      rejections_this_minute: 0, rejections_by_reason: zeros(['external_payload_namespace_bytes_exhausted',
        'external_payload_namespace_objects_exhausted', 'external_payload_namespace_quota_unavailable']),
      configuration_status: 'valid', measurement_status: 'available',
      label_cardinality_policy: {namespace: 'request_scope_not_label', reason: 'finite_three_reason_inventory'},
    },
  });
  const empty = {...definition.failures, max_consecutive_failures: 0, failed_task_count: 0, workflow_type_count: 0, by_workflow_type: []};
  const check = (recorded, failures, usage, label) => {
    assert.equal(recorded.status, 200, `${label} status`);
    const {generated_at: generatedAt, namespace: observed, metrics, cardinality} = recorded.response;
    assert.deepStrictEqual(Object.keys(recorded.response).sort(), ['cardinality', 'generated_at', 'metrics', 'namespace'], `${label} fields`);
    assert.match(generatedAt, MICROS_OR_MILLIS, `${label} generated_at in UTC`);
    assert.equal(observed, namespace, `${label} namespace`);
    const {dw_projection_drift_total: drift, ...scoped} = metrics;
    assert.deepStrictEqual(scoped, expected(failures, usage), `${label} namespace metrics`);
    assert.ok(drift && Array.isArray(drift.by_table), `${label} projection drift family`);
    assert.deepStrictEqual(Object.keys(drift).sort(), ['by_table', 'label_cardinality_policy', 'scope', 'table_count', 'tables_with_drift', 'total'],
      `${label} drift fields`);
    const tables = definition.projection_tables;
    assert.deepStrictEqual(drift.by_table.map(row => row.table), tables, `${label} drift tables`);
    for (const row of drift.by_table) {
      assert.deepStrictEqual(Object.keys(row).sort(), ['needs_rebuild', 'table'], `${label} ${row.table} drift fields`);
      assert.ok(Number.isInteger(row.needs_rebuild) && row.needs_rebuild >= 0, `${label} ${row.table} drift`);
    }
    const counts = drift.by_table.map(row => row.needs_rebuild);
    assert.deepStrictEqual(
      {total: drift.total, table_count: drift.table_count, tables_with_drift: drift.tables_with_drift, scope: drift.scope,
        label_cardinality_policy: drift.label_cardinality_policy},
      {total: counts.reduce((sum, count) => sum + count, 0), table_count: tables.length,
        tables_with_drift: counts.filter(count => count > 0).length, scope: 'server',
        label_cardinality_policy: {namespace: 'server_scope_no_label', table: {cardinality_class: 'finite_projection_table_inventory',
          values: tables, selection: 'fixed_projection_table_inventory'}}},
      `${label} drift totals`);
    assert.deepStrictEqual(cardinality, {metric_label_sets: Object.fromEntries(Object.entries(metrics)
      .map(([name, family]) => [name, family.label_cardinality_policy]))}, `${label} cardinality`);
  };
  check(state.empty, empty, zeros(resources), 'empty namespace');
  check(state.read, definition.failures, definition.usage, 'after the scenario');
  check(state.posted, definition.failures, definition.usage, 'read by POST');
  return projection;
}
