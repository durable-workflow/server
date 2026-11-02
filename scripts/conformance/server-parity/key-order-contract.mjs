import assert from 'node:assert/strict';

// Reviewed key order in plain JSON input and search attribute validation
// (key-order-probe.php), recorded against published Server 2.11.7. Stored
// input blobs carry the encoded map order, and message lists carry the
// validation order. The fixture's `expected` holds the whole observation.
export function checkKeyOrder(fixture, observation) {
  const definition = fixture.key_order;
  const state = observation.key_order;
  const projection = {scope: 'key_order'};
  assert.ok(state && typeof state === 'object', 'actual key order observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_request_bodies'},
      'embedded key order explicitly inapplicable');
    return projection;
  }
  assert.deepStrictEqual(Object.keys(definition.expected.starts).sort(), Object.keys(definition.starts).sort(),
    'every key order start has an expectation');
  assert.deepStrictEqual(state, {applicable: true, ...definition.expected}, 'key order');
  return projection;
}
