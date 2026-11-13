import assert from 'node:assert/strict';
import {expectedFor} from './native-divergences.mjs';

export function checkClosedWaitTimeouts(fixture, observation, target) {
  const state = observation.closed_wait_timeouts;
  // Native's own-close timer cancellation is the named divergence
  // run-close-settles-open-work (Server #1193).
  const expected = expectedFor(fixture.closed_wait_timeouts, target);
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'});
  } else {
    assert.deepStrictEqual(state, {applicable: true, cases: expected},
      'terminal waits preserve the published PHP history on every close path');
  }
  return {scope: 'closed_wait_timeouts', cases: Object.keys(fixture.closed_wait_timeouts.expected).length};
}
