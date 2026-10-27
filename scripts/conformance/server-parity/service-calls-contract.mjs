import assert from 'node:assert/strict';
import {assertDistinctIds, catalogContext, matchCatalog} from './catalog-placeholders.mjs';

// Reviewed Nexus service-call execution, show and cancel (Server 2.11.1's
// ServiceCatalogController over Workflow 2.10.2's DefaultServiceControlPlane)
// for the handler kinds that stay accepted, with the boundary's refusals,
// handler failures and retries, deadlines and idempotent replays. Every
// answer is exact except generated ids and times (see catalog-placeholders).
// Embedded applications have no HTTP service calls.
export function checkServiceCalls(fixture, observation, workflowId) {
  const definition = fixture.service_calls;
  const state = observation.service_calls;
  const projection = {scope: 'service_calls', cases: definition.requests.length};
  assert.ok(state && typeof state === 'object', 'actual service calls observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_service_calls'},
      'embedded service calls explicitly inapplicable');
    return projection;
  }
  const own = `${workflowId}-calls`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace], [true, own], 'service calls namespace');
  const labels = definition.requests.map(request => request.label);
  assert.deepStrictEqual(Object.keys(state.responses ?? {}).sort(), [...labels].sort(), 'every service-call request answered');
  const context = catalogContext(own);
  for (const label of labels) {
    matchCatalog(definition.expected[label], state.responses[label], context, label);
  }
  assertDistinctIds(context, 'each id names one call, handler or catalog object');
  return projection;
}
