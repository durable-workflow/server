import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWaterlineImport} from '../../scripts/conformance/server-parity/waterline-import-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/waterline-import.json', import.meta.url)));
// The observation published Server 2.11.7 returned for this fixture (2.11.1
// returned the same answers with the pre-#770 history sizes).
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/waterline-import-observation.json', import.meta.url)));
const id = 'pr1-waterline-import';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', waterline_import: state};
};
const check = state => checkWaterlineImport(fixture, state, id);

test('the published 2.11.7 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'waterline_import'});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', waterline_import:
    {applicable: false, reason: 'embedded_has_no_http_waterline_import'}}), {scope: 'waterline_import'});
  assert.throws(() => check({mode: 'embedded', waterline_import: {applicable: true}}), /embedded waterline import/);
});

test('the recording covers every refusal and read', () => {
  const expected = fixture.waterline_import.expected;
  for (const label of ['running', 'completed']) {
    for (const [operation, answer] of Object.entries(expected[`refuse_${label}`])) {
      assert.equal(answer.status, 409, `${label} ${operation}`);
    }
  }
  assert.equal(expected.project.status, 201);
  assert.equal(expected.project.response.status, 'projected');
  assert.equal(expected.native_collision.response.reason, 'native_v2_identity_collision');
  assert.equal(expected.read_project_describe.response.action_blocked_reason, 'v1_projection_read_only');
  assert.ok(expected.read_project_history.response.migration_projection, 'history carries the projection metadata');
  assert.ok(expected.read_project_export.response.migration_projection, 'the export carries the projection metadata');
  for (const name of ['project', 'running', 'failed', 'offsets', 'bare']) {
    assert.equal(expected[`read_${name}_export`].response.integrity_verified, true, `${name} export checksum covers its bundle`);
  }
});

// server#587: native's query contract adds query_history_unavailable right
// after run_not_active; it is checked and projected away for native only.
const nativeObservation = (reasons) => observation(state => {
  for (const step of ['refuse_running', 'refuse_completed']) {
    state[step].query.response.control_plane.contract.rejection_reasons = reasons(
      [...state[step].query.response.control_plane.contract.rejection_reasons]);
  }
  for (const value of Object.values(state)) addRedrive(value);
});
const addRedrive = (value) => {
  if (Array.isArray(value)) return value.forEach(addRedrive);
  if (!value || typeof value !== 'object') return;
  for (const [key, item] of Object.entries(value)) {
    if (key === 'actions' && item && typeof item === 'object' && Object.hasOwn(item, 'can_archive')) {
      Object.assign(item, {can_redrive: false, redrive_blocked_reason: 'run_not_failed'});
    } else addRedrive(item);
  }
};
const insertAfter = (reasons, after, reason) => {
  reasons.splice(reasons.indexOf(after) + 1, 0, reason);
  return reasons;
};

test('the published query contracts list run_not_active to place the native reason after', () => {
  for (const step of ['refuse_running', 'refuse_completed']) {
    const reasons = fixture.waterline_import.expected[step].query.response.control_plane.contract.rejection_reasons;
    assert.ok(reasons.includes('run_not_active') && !reasons.includes('query_history_unavailable'), step);
  }
});

test('native query contracts list query_history_unavailable after run_not_active', () => {
  const native = nativeObservation(reasons => insertAfter(reasons, 'run_not_active', 'query_history_unavailable'));
  assert.deepStrictEqual(checkWaterlineImport(fixture, native, id, 'rust'), {scope: 'waterline_import'});
  // Published PHP never lists it.
  assert.throws(() => check(native), /waterline import/);
});

for (const [label, reasons] of [
  ['without it', reasons => reasons],
  ['out of place', reasons => [...reasons, 'query_history_unavailable']],
  ['twice', reasons => [...insertAfter(reasons, 'run_not_active', 'query_history_unavailable'), 'query_history_unavailable']],
]) {
  test(`a native query contract ${label} is refused`, () => {
    assert.throws(() => checkWaterlineImport(fixture, nativeObservation(reasons), id, 'rust'),
      /native query contract lists query_history_unavailable once after run_not_active/);
  });
}

for (const [label, change, message] of [
  // Native answered 501 for the import route before this fixture.
  ['an unimplemented route', state => {
    state.project = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /waterline import project/],
  ['a dry run that writes the projection', state => {
    state.dry_run_describe = structuredClone(state.read_project_describe);
  }, /waterline import dry_run_describe/],
  ['a repeat projected again', state => {
    state.repeat = structuredClone(state.project);
  }, /waterline import repeat/],
  ['a changed source accepted as a repeat', state => {
    state.changed = structuredClone(state.repeat);
  }, /waterline import changed/],
  ['a projection taken over by another namespace', state => {
    state.other_namespace = structuredClone(state.project);
  }, /waterline import other_namespace/],
  ['a native identity overwritten', state => {
    state.native_collision.status = 201;
  }, /waterline import native_collision/],
  ['only the first of several normalization errors', state => {
    state.reject_all.response.errors = state.reject_all.response.errors.slice(0, 1);
  }, /waterline import reject_all/],
  ['a describe that offers actions', state => {
    state.read_project_describe.response.actions.can_signal = true;
  }, /waterline import read_project_describe/],
  ['a describe without projection metadata', state => {
    delete state.read_running_describe.response.migration_projection;
  }, /waterline import read_running_describe/],
  ['a history without projection metadata', state => {
    delete state.read_project_history.response.migration_projection;
  }, /waterline import read_project_history/],
  ['an export whose checksum was not recomputed', state => {
    state.read_project_export.response.integrity_verified = false;
  }, /waterline import read_project_export/],
  ['a signal admitted on a running projection', state => {
    state.refuse_running.signal = {status: 202, response: {outcome: 'signal_received'}};
  }, /waterline import refuse_running/],
  ['a cooperative cancellation refused with the operation reason', state => {
    state.refuse_completed.request_cancellation.response.reason = 'v1_projection_read_only';
  }, /waterline import refuse_completed/],
  ['an event order by hashed id', state => {
    state.read_project_history.response.events.reverse();
  }, /waterline import read_project_history/],
  ['a missing step', state => {
    delete state.read_list_alt;
  }, /waterline import read_list_alt/],
  ['an extra step', state => {
    state.unexpected = {status: 200};
  }, /waterline import steps/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
