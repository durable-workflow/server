import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkBridgeAdapters} from '../../scripts/conformance/server-parity/bridge-adapters-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/bridge-adapters.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/bridge-adapters-observation.json', import.meta.url)));
const id = 'pr1-bridge-adapters';
const observation = (change = () => {}, base = recorded) => {
  const state = structuredClone(base);
  change(state);
  return {mode: 'http', bridge_adapters: state};
};
const check = state => checkBridgeAdapters(fixture, state, id);
// The intended answers: the bridge update admitted like the regular route.
const intended = JSON.parse(JSON.stringify(fixture.bridge_adapters.expected).replaceAll('{id}', id));

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'bridge_adapters'});
});

test('the intended update acceptance and deduplication are accepted too', () => {
  assert.deepStrictEqual(check(observation(() => {}, intended)), {scope: 'bridge_adapters'});
  assert.equal(intended.update.status, 202);
  assert.equal(intended.update_again.response.control_plane_outcome, 'deduped_existing_command');
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', bridge_adapters:
    {applicable: false, reason: 'embedded_has_no_http_bridge_adapters'}}), {scope: 'bridge_adapters'});
});

for (const [label, change, message] of [
  // Native answered 501 for the bridge route before this fixture.
  ['an unimplemented route', state => {
    state.start = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /bridge adapters start/],
  ['only part of the published update defect', state => {
    state.update = structuredClone(intended.update);
  }, /bridge adapters update_again|bridge adapters history_event_types/],
  ['an accepted update that is not deduplicated', state => {
    Object.assign(state, structuredClone({update: intended.update, history_event_types: intended.history_event_types}));
    state.update_again = structuredClone(intended.update);
  }, /bridge adapters update_again/],
  ['a derived workflow id from another digest', state => {
    state.start_derived.response.workflow_id = 'bridge-acme.crm:v1-0000';
  }, /bridge adapters start_derived/],
  ['a replayed signal that is signalled again', state => {
    state.signal_again = structuredClone(state.signal);
  }, /bridge adapters signal_again/],
  ['a deduplication that ignores the adapter', state => {
    state.signal_other_adapter = structuredClone(state.signal_again);
  }, /bridge adapters signal_other_adapter/],
  ['a refusal that echoes unredacted target fields', state => {
    state.unsupported_action.response.target.secret = 'not echoed';
  }, /bridge adapters unsupported_action/],
  ['reject_duplicate treated as use_existing', state => {
    state.start_reject_duplicate = structuredClone(state.start_again);
  }, /bridge adapters start_reject_duplicate/],
  ['the namespace check before the type check', state => {
    state.start_other_type.response.message = state.start_other_namespace.response.message;
  }, /bridge adapters start_other_type/],
  ['a missing step', state => {
    delete state.signal_bad_target;
  }, /bridge adapters signal_bad_target/],
  ['an extra step', state => {
    state.extra = {status: 200};
  }, /bridge adapters steps/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
