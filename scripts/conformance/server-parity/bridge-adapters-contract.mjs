import assert from 'node:assert/strict';

// Reviewed bridge adapter webhooks (BridgeAdapterController), recorded
// against published Server 2.11.1. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`, run IDs
// as labels and derived workflow IDs as `{derived-<key>}`. Every step
// compares exactly.
//
// Published defect boundary: a bridge update skips external update admission,
// so an update to an SDK-worker workflow is rejected
// workflow_definition_unavailable (fixture `published_defects`). At those
// steps the published answers or the intended acceptance and deduplication
// are accepted, as a set; nothing else is relaxed.
export function checkBridgeAdapters(fixture, observation, workflowId) {
  const state = observation.bridge_adapters;
  const projection = {scope: 'bridge_adapters'};
  assert.ok(state && typeof state === 'object', 'actual bridge adapters observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_bridge_adapters'},
      'embedded bridge adapters explicitly inapplicable');
    return projection;
  }
  const definition = fixture.bridge_adapters;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  const defect = definition.published_defects.update_skips_external_admission.published;
  const steps = Object.keys(defect);
  let published = true;
  try {
    for (const step of steps) assert.deepStrictEqual(actual[step], defect[step]);
  } catch {
    published = false;
  }
  if (published) {
    for (const step of steps) actual[step] = definition.expected[step];
  }
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `bridge adapters ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'bridge adapters steps');
  return projection;
}
