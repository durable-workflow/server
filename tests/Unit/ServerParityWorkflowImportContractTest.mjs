import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkflowImport} from '../../scripts/conformance/server-parity/workflow-import-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/workflow-import.json', import.meta.url)));
// The observation published Server 2.11.8 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/workflow-import-observation.json', import.meta.url)));
const id = 'pr1-workflow-import';
const observation = (change = () => {}, base = recorded) => {
  const state = structuredClone(base);
  change(state);
  return {mode: 'http', workflow_import: state};
};
const check = state => checkWorkflowImport(fixture, state, id);
// The isolated answers: no namespace sees or changes another's runs. Since
// 2.11.8 they are also the published ones.
const intended = JSON.parse(JSON.stringify(fixture.workflow_import.expected).replaceAll('{id}', id));

test('the published 2.11.8 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'workflow_import'});
});

test('the isolated cross-namespace answers are accepted too', () => {
  assert.deepStrictEqual(check(observation(() => {}, intended)), {scope: 'workflow_import'});
  assert.equal(intended.duplicate_other_namespace.status, 422);
  assert.deepStrictEqual(intended.duplicate_other_namespace.response.eligibility.errors.map(error => error.rule), ['target.run_id_conflict']);
  assert.equal(intended.attach.status, 422);
  assert.deepStrictEqual(intended.attach_source_describe_after, recorded.attach_source_describe_before);
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', workflow_import:
    {applicable: false, reason: 'embedded_has_no_http_workflow_import'}}), {scope: 'workflow_import'});
});

const rows = step => step.response.rows;
for (const [label, change, message] of [
  // Native answered 501 for the import route before this fixture.
  ['an unimplemented route', state => {
    state.import = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /workflow import import/],
  // 2.11.1 to 2.11.7 attached the run to the other namespace's instance
  // (#765); 2.11.8 refuses it as native does.
  ['an attach into another namespace', state => {
    state.attach = structuredClone(state.import);
  }, /workflow import attach/],
  ['a cross-namespace duplicate that writes a second run', state => {
    state.duplicate_other_namespace = structuredClone(state.import);
  }, /workflow import duplicate_other_namespace/],
  ['a dry run that estimates memos', state => {
    rows(state.dry_run).workflow_memos = 2;
  }, /workflow import dry_run/],
  ['a repeat with another import_id imported again', state => {
    state.duplicate = structuredClone(state.signature_verified);
  }, /workflow import duplicate/],
  ['an import_id kept with its whitespace', state => {
    state.import.response.import_id = '  imp-1  ';
  }, /workflow import import/],
  ['a dry run of an imported bundle answered dry_run', state => {
    state.duplicate_dry_run.response.status = 'dry_run';
  }, /workflow import duplicate_dry_run/],
  ['a checksum over the request before normalization', state => {
    state.normalized_checksum = structuredClone(state.unnormalized_checksum);
  }, /workflow import normalized_checksum/],
  ['a canonical form that leaves unicode unescaped', state => {
    const integrity = state.import.response.integrity;
    integrity.integrity.recomputed_checksum = '0'.repeat(64);
    integrity.integrity.checksum_matches = false;
  }, /workflow import import/],
  ['string redaction paths refused', state => {
    state.redaction_string_paths = structuredClone(state.redaction_object_paths);
  }, /workflow import redaction_string_paths/],
  ['identity errors in the verifier order', state => {
    state.identity_missing.response.eligibility.errors.splice(1, 2,
      ...state.identity_missing.response.eligibility.errors.slice(1, 3).reverse());
  }, /workflow import identity_missing/],
  ['every leased task and running attempt reported', state => {
    const errors = state.leased_task.response.eligibility.errors;
    errors.push(...state.running_attempt.response.eligibility.errors);
  }, /workflow import leased_task/],
  ['a failed write that leaves the run', state => {
    state.write_failed_describe = structuredClone(state.read_describe);
  }, /workflow import write_failed_describe/],
  ['an unrequired signature reported as required', state => {
    state.signature_verified = structuredClone(state.signature_required_no_key);
  }, /workflow import signature_verified/],
  ['a missing step', state => {
    delete state.read_history;
  }, /workflow import read_history/],
  ['an extra step', state => {
    state.extra = {status: 200};
  }, /workflow import steps/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
