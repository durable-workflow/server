import assert from 'node:assert/strict';

// Reviewed request bodies nested to PHP's json_decode depth
// (deep-json-bodies-probe.php), recorded against published Server 2.11.7.
// The fixture's `expected` holds the whole observation.
export function checkDeepJsonBodies(fixture, observation) {
  const definition = fixture.deep_json_bodies;
  const state = observation.deep_json_bodies;
  const projection = {scope: 'deep_json_bodies'};
  assert.ok(state && typeof state === 'object', 'actual deep json bodies observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_request_bodies'},
      'embedded deep json bodies explicitly inapplicable');
    return projection;
  }
  assert.deepStrictEqual(state, {applicable: true, ...definition.expected}, 'deep json bodies');
  return projection;
}
