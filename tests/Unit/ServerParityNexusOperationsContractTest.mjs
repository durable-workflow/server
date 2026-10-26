import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkNexusOperations} from '../../scripts/conformance/server-parity/nexus-operations-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/nexus-operations.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/nexus-operations-observation.json', import.meta.url)));
const id = 'pr1-nexus-operations';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', nexus_operations: state};
};
const check = state => checkNexusOperations(fixture, state, id);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'nexus_operations'});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', nexus_operations:
    {applicable: false, reason: 'embedded_has_no_http_nexus_operations'}}), {scope: 'nexus_operations'});
});

for (const [label, change, message] of [
  // Native answered 501 for both routes before this fixture.
  ['an unimplemented route', state => {
    state.workflow = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /nexus operations workflow/],
  ['an unknown workflow refused as not found', state => {
    state.unknown_workflow = {status: 404, response: {reason: 'instance_not_found'}};
  }, /nexus operations unknown_workflow/],
  ['the default namespace for every request', state => {
    state.other_namespace.response.caller_namespace = 'default';
  }, /nexus operations other_namespace/],
  ['a zero limit kept as zero', state => {
    state.limit_zero.response.limit = 0;
  }, /nexus operations limit_zero/],
  ['an exponent read as its leading digit', state => {
    state.limit_exponent.response.limit = 1;
  }, /nexus operations limit_exponent/],
  ['an uncapped limit', state => {
    state.limit_above_cap.response.limit = 1000;
  }, /nexus operations limit_above_cap/],
  ['a run listing without its run id', state => {
    delete state.run.response.workflow_run_id;
  }, /nexus operations run/],
  ['a missing step', state => {
    delete state.limit_padded;
  }, /nexus operations limit_padded/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
