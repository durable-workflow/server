import assert from 'node:assert/strict';

// Reviewed bridge adapter webhooks (BridgeAdapterController), recorded
// against published Server 2.11.7. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`, run IDs
// as labels and derived workflow IDs as `{derived-<key>}`. Every step
// compares exactly.
//
// Published 2.11.1 to 2.11.5 answered two sets of steps otherwise: a bridge
// update to an SDK-worker workflow was rejected workflow_definition_unavailable
// (Server #747), and a start for an id another namespace holds named that
// namespace's type or the namespace difference (Server #777). 2.11.7 gives
// the intended answers, so neither is accepted any more.
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
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `bridge adapters ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'bridge adapters steps');
  return projection;
}
