import assert from 'node:assert/strict';

// Waterline v1 projection import (LegacyV1ProjectionController), recorded
// against published Server 2.11.1. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`,
// projected identities, event ids, dedupe keys and export checksums as
// `{<label>.<token>}` labels, and import and export times as placeholders.
// Every step compares exactly.
export function checkWaterlineImport(fixture, observation, workflowId) {
  const state = observation.waterline_import;
  const projection = {scope: 'waterline_import'};
  assert.ok(state && typeof state === 'object', 'actual waterline import observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_waterline_import'},
      'embedded waterline import explicitly inapplicable');
    return projection;
  }
  const definition = fixture.waterline_import;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `waterline import ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'waterline import steps');
  return projection;
}
