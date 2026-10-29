import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkBridgeAdapters} from '../../scripts/conformance/server-parity/bridge-adapters-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/bridge-adapters.json', import.meta.url)));
// The observation published Server 2.11.7 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/bridge-adapters-observation.json', import.meta.url)));
const id = 'pr1-bridge-adapters';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', bridge_adapters: state};
};
const check = state => checkBridgeAdapters(fixture, state, id);

// Published 2.11.1 to 2.11.5 rejected the bridge update to an SDK-worker
// workflow (Server #747) and named a foreign instance's type or namespace
// (Server #777). Those answers are no longer accepted.
const rejectedUpdate = answer => ({status: 422, response: {...answer.response, accepted: false, outcome: 'rejected',
  reason: 'workflow_definition_unavailable', control_plane_outcome: 'rejected_workflow_definition_unavailable'}});
const reservedType = `Workflow instance [${id}] is reserved for durable type [parity.v1.echo] and cannot be reused for [parity.v1.bridge].`;
const otherNamespace = `Workflow instance [${id}] cannot be reused with a different namespace.`;

test('the published 2.11.7 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'bridge_adapters'});
});

test('the recording carries the intended update and foreign-instance answers', () => {
  assert.equal(recorded.update.status, 202);
  assert.equal(recorded.update_again.response.control_plane_outcome, 'deduped_existing_command');
  for (const step of ['start_other_type', 'start_other_namespace']) {
    assert.equal(recorded[step].response.message, `Workflow instance [${id}] is unavailable.`, step);
  }
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
  ['the published update rejection', state => {
    state.update = rejectedUpdate(state.update);
    state.update_again = rejectedUpdate(state.update_again);
  }, /bridge adapters update/],
  ['an accepted update that is not deduplicated', state => {
    state.update_again = structuredClone(state.update);
  }, /bridge adapters update_again/],
  ['a foreign instance refused with its type named', state => {
    state.start_other_type.response.message = reservedType;
  }, /bridge adapters start_other_type/],
  ['a foreign instance refused with the namespace difference', state => {
    state.start_other_type.response.message = otherNamespace;
    state.start_other_namespace.response.message = otherNamespace;
  }, /bridge adapters start_other_type/],
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
