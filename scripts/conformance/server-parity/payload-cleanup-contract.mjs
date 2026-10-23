import assert from 'node:assert/strict';

const WHOLE_SECOND = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000000Z$/;

// Reviewed external payload cleanup answers (SystemController::
// externalPayloadCleanupStatus and externalPayloadCleanupPass). Every answer is
// exact except the namespace placeholder and a pass's whole-second time.
export function checkPayloadCleanup(fixture, observation, workflowId) {
  const definition = fixture.payload_cleanup;
  const state = observation.payload_cleanup;
  const projection = {scope: 'payload_cleanup', cases: definition.requests.length};
  assert.ok(state && typeof state === 'object', 'actual payload cleanup observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_system_routes'}, 'embedded cleanup explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-cleanup`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace], [true, namespace], 'cleanup namespace');
  assert.deepStrictEqual(Object.keys(state.requests), definition.requests.map(([label]) => label), 'every request recorded in order');
  for (const [label] of definition.requests) {
    const recorded = state.requests[label];
    const [status, body] = definition.expected[label];
    const completed = recorded.response?.cleanup?.last_pass?.completed_at;
    if (body.cleanup?.last_pass?.completed_at === '@second') {
      assert.match(String(completed), WHOLE_SECOND, `${label}: whole-second completion time`);
    }
    const expected = JSON.parse(JSON.stringify(body).split('"@own"').join(JSON.stringify(namespace))
      .split('"@second"').join(JSON.stringify(completed ?? null)));
    assert.deepStrictEqual([recorded.status, recorded.response], [status, expected], label);
  }
  return projection;
}
