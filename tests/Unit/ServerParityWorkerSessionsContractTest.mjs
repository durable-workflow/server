import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkerSessions} from '../../scripts/conformance/server-parity/worker-sessions-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/worker-sessions.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/worker-sessions-observation.json', import.meta.url)));
const id = 'pr1-worker-sessions';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', worker_sessions: state};
};
const check = state => checkWorkerSessions(fixture, state, id);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'worker_sessions'});
});

test('the intended clearing of failure_reason on reacquire is accepted too', () => {
  assert.deepStrictEqual(check(observation(state => {
    state.reacquired.response.session.failure_reason = null;
    state.orphan_reacquired.response.session.failure_reason = null;
    state.list_sessions.find(session => session.session_id.endsWith('-wss-held')).failure_reason = null;
  })), {scope: 'worker_sessions'});
});

test('a ttl_span one second short, as PHP stamps created_at in the next second, is accepted', () => {
  assert.deepStrictEqual(check(observation(state => {
    state.reacquire_terminal.response.session.ttl_span -= 1;
  })), {scope: 'worker_sessions'});
});

test('a ttl_span two seconds short or one second long is still refused', () => {
  for (const change of [-2, 1]) {
    assert.throws(() => check(observation(state => {
      state.reacquire_terminal.response.session.ttl_span += change;
    })), /worker sessions reacquire_terminal/);
  }
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', worker_sessions:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'worker_sessions'});
});

for (const [label, change, message] of [
  // Native answered 501 for every session route before this fixture.
  ['an unimplemented create', state => {
    state.created = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /worker sessions created/],
  ['a protocol gate that admits 1.7', state => {
    state.gate_protocol_1_7 = structuredClone(state.gate_protocol_1_8_empty);
  }, /worker sessions gate_protocol_1_7/],
  ['requirements checked after the lookup', state => {
    state.closed_create_unmet = structuredClone(state.closed_create);
  }, /worker sessions closed_create_unmet/],
  ['a heartbeat that rewrites the stored lease', state => {
    state.heartbeat.response.session.lease_seconds = 5;
  }, /worker sessions heartbeat/],
  ['a reuse that extends the TTL deadline', state => {
    state.reused.response.session.ttl_span = 9999;
  }, /worker sessions reused/],
  ['lease expiry not applied on read', state => {
    state.short_expired.response.session.status = 'active';
    state.short_expired.response.session.failure_reason = null;
  }, /worker sessions short_expired/],
  ['an orphan not detected on read', state => {
    state.held_orphaned.response.session.status = 'active';
  }, /worker sessions held_orphaned/],
  ['a closed session that can be reacquired', state => {
    state.closed_create = {status: 200, response: {outcome: 'reacquired'}};
  }, /worker sessions closed_create/],
  ['a different failure_reason after reacquire', state => {
    state.reacquired.response.session.failure_reason = 'ttl_expired';
  }, /worker sessions reacquired/],
  ['a missing step', state => {
    delete state.ttl_close;
  }, /worker sessions ttl_close/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
