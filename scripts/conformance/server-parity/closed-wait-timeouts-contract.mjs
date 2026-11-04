import assert from 'node:assert/strict';

export function checkClosedWaitTimeouts(fixture, observation) {
  const state = observation.closed_wait_timeouts;
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'});
  } else {
    assert.deepStrictEqual(state, {applicable: true, cases: fixture.closed_wait_timeouts.expected},
      'terminal waits preserve the published PHP history on every close path');
  }
  return {scope: 'closed_wait_timeouts', cases: Object.keys(fixture.closed_wait_timeouts.expected).length};
}
