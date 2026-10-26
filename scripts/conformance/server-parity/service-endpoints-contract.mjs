import assert from 'node:assert/strict';
import {assertDistinctIds, catalogContext, matchCatalog} from './catalog-placeholders.mjs';

// Reviewed service catalog administration (Server 2.11.1's
// ServiceCatalogController endpoint, service and operation routes) and the
// catalog reads over its rows. Every response is exact except generated
// values: "{id:NAME}" is a lowercase ULID, the same wherever NAME recurs and
// distinct across names; "{time}" is a whole-second UTC ISO-8601 time and
// "{time:NAME}" one that recurs exactly. "@own" is the fixture's namespace,
// also inside messages. Embedded applications have no HTTP catalog.
export function checkServiceEndpoints(fixture, observation, workflowId) {
  const definition = fixture.service_endpoints;
  const state = observation.service_endpoints;
  const projection = {scope: 'service_endpoints', cases: definition.requests.length};
  assert.ok(state && typeof state === 'object', 'actual service endpoints observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_service_endpoints'},
      'embedded service endpoints explicitly inapplicable');
    return projection;
  }
  const own = `${workflowId}-endpoints`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace], [true, own], 'service endpoints namespace');
  const labels = definition.requests.map(request => request.label);
  assert.deepStrictEqual(Object.keys(state.responses ?? {}).sort(), [...labels].sort(), 'every catalog request answered');
  const context = catalogContext(own);
  for (const label of labels) {
    matchCatalog(definition.expected[label], state.responses[label], context, label);
  }
  assertDistinctIds(context, 'each id names one catalog object');
  return projection;
}

