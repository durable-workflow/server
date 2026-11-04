import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkClosedWaitTimeouts} from '../../scripts/conformance/server-parity/closed-wait-timeouts-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/closed-wait-timeouts.json', import.meta.url), 'utf8'));
const observed = () => ({mode: 'http', closed_wait_timeouts: {applicable: true,
  cases: structuredClone(fixture.closed_wait_timeouts.expected)}});

test('all ten published PHP close histories pass', () => {
  assert.deepStrictEqual(checkClosedWaitTimeouts(fixture, observed()), {scope: 'closed_wait_timeouts', cases: 10});
});

test('embedded explicitly excludes the HTTP worker contract', () => {
  assert.deepStrictEqual(checkClosedWaitTimeouts(fixture,
    {mode: 'embedded', closed_wait_timeouts: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'closed_wait_timeouts', cases: 10});
  assert.throws(() => checkClosedWaitTimeouts(fixture, {mode: 'embedded', closed_wait_timeouts: {applicable: true}}));
});

for (const kind of ['condition', 'signal']) {
  for (const close of ['complete', 'fail', 'continue', 'cancel', 'terminate']) {
    test(`rejects an altered ${kind} ${close} history`, () => {
      const observation = observed();
      const events = observation.closed_wait_timeouts.cases[`${kind}_${close}`].history;
      if (['cancel', 'terminate'].includes(close)) {
        events.splice(events.indexOf('TimerCancelled'), 1);
      } else {
        events.splice(-1, 0, 'TimerCancelled');
      }
      assert.throws(() => checkClosedWaitTimeouts(fixture, observation));
    });
  }
}

test('a missing close path cannot masquerade as a complete comparison', () => {
  const observation = observed();
  delete observation.closed_wait_timeouts.cases.signal_continue;
  assert.throws(() => checkClosedWaitTimeouts(fixture, observation));
});

test('a workflow result with the wrong terminal state fails', () => {
  const observation = observed();
  observation.closed_wait_timeouts.cases.condition_fail.status = 'completed';
  assert.throws(() => checkClosedWaitTimeouts(fixture, observation));
});
