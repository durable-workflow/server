import assert from 'node:assert/strict';

// Reviewed caller-indexed Nexus operation reads (ServiceCatalogController::
// nexusOperationsForWorkflow and nexusOperationsForRun), recorded against
// published Server 2.11.1. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}` and its
// run as `{run}`. Every step compares exactly.
export function checkNexusOperations(fixture, observation, workflowId) {
  const state = observation.nexus_operations;
  const projection = {scope: 'nexus_operations'};
  assert.ok(state && typeof state === 'object', 'actual nexus operations observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_nexus_operations'},
      'embedded nexus operations explicitly inapplicable');
    return projection;
  }
  const definition = fixture.nexus_operations;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `nexus operations ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'nexus operations steps');
  return projection;
}
