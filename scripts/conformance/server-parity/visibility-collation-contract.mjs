import assert from 'node:assert/strict';

// Reviewed text comparisons in structured list queries
// (visibility-collation-probe.php), recorded against published Server 2.11.7
// on SQLite and on MySQL 8. The observation names its collation, `exact`
// (SQLite, PostgreSQL) or `mysql` (utf8mb4_unicode_ci), and `expected`
// holds each collation's whole observation.
export function checkVisibilityCollation(fixture, observation) {
  const definition = fixture.visibility_collation;
  const state = observation.visibility_collation;
  assert.ok(state && typeof state === 'object', 'actual visibility collation observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_list_queries'},
      'embedded visibility collation explicitly inapplicable');
    return {scope: 'visibility_collation'};
  }
  assert.ok(Object.hasOwn(definition.expected, state.collation), `visibility collation ${state.collation}`);
  assert.deepStrictEqual(state, {applicable: true, collation: state.collation, ...definition.expected[state.collation]},
    `visibility collation (${state.collation})`);
  return {scope: 'visibility_collation', collation: state.collation};
}
