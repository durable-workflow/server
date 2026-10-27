import assert from 'node:assert/strict';

// Reviewed bridge adapter webhooks (BridgeAdapterController), recorded
// against published Server 2.11.1. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`, run IDs
// as labels and derived workflow IDs as `{derived-<key>}`. Every step
// compares exactly.
//
// Published defect boundaries (fixture `published_defects`): a bridge update
// skips external update admission, so an update to an SDK-worker workflow is
// rejected workflow_definition_unavailable; and a start for an id another
// namespace holds names that namespace's type (published 2.11.1 to 2.11.4)
// or the namespace difference (2.11.5), not the intended unavailable
// (Server #777). At each
// defect's steps the published answers or the intended ones are accepted, as
// a set per defect; nothing else is relaxed.
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
  for (const {published: defect} of Object.values(definition.published_defects)) {
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
  }
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `bridge adapters ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'bridge adapters steps');
  return projection;
}
