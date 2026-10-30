import assert from 'node:assert/strict';

// Reviewed child memos and search attributes (child-visibility-probe.php),
// recorded against published Server 2.11.7. The fixture's `expected` holds
// the whole observation.
export function checkChildVisibility(fixture, observation) {
  const definition = fixture.child_visibility;
  const state = observation.child_visibility;
  const projection = {scope: 'child_visibility'};
  assert.ok(state && typeof state === 'object', 'actual child visibility observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded child visibility explicitly inapplicable');
    return projection;
  }
  assert.deepStrictEqual(state, {applicable: true, ...definition.expected}, 'child visibility');
  return projection;
}
