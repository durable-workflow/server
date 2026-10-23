import assert from 'node:assert/strict';

// Reviewed service catalog reads (Server 2.11's ServiceCatalogReadController)
// in a namespace with no catalog objects. Every response is exact; "@own"
// stands for the fixture's own namespace. Embedded applications have no HTTP
// catalog.
export function checkServiceCatalog(fixture, observation, workflowId) {
  const definition = fixture.service_catalog;
  const state = observation.service_catalog;
  const projection = {scope: 'service_catalog', cases: definition.requests.length};
  assert.ok(state && typeof state === 'object', 'actual service catalog observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_service_catalog'},
      'embedded service catalog explicitly inapplicable');
    return projection;
  }
  const own = `${workflowId}-catalog`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace], [true, own], 'catalog namespace');
  const labels = definition.requests.map(request => request.label);
  assert.deepStrictEqual(Object.keys(state.responses ?? {}).sort(), [...labels].sort(), 'every catalog request answered');
  const substitute = value => JSON.parse(JSON.stringify(value).split('"@own"').join(JSON.stringify(own)));
  for (const label of labels) {
    assert.deepStrictEqual(state.responses[label], substitute(definition.expected[label]), label);
  }
  return projection;
}
