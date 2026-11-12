import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkflowImport} from '../../scripts/conformance/server-parity/workflow-import-contract.mjs';
import {checkWaterlineImport} from '../../scripts/conformance/server-parity/waterline-import-contract.mjs';

// server#980: native describe actions add Core's redrive decision, checked
// for consistency and projected away before the published comparison.
const read = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url)));
const cases = [
  ['workflow import', read('../Fixtures/ServerParity/workflow-import.json'),
    read('../Fixtures/ServerParityExpected/workflow-import-observation.json'), 'pr1-workflow-import', 'workflow_import', checkWorkflowImport],
  ['waterline import', read('../Fixtures/ServerParity/waterline-import.json'),
    read('../Fixtures/ServerParityExpected/waterline-import-observation.json'), 'pr1-waterline-import', 'waterline_import', checkWaterlineImport],
];

// Adds the two native fields to every describe actions object.
function nativeActions(value, add) {
  if (Array.isArray(value)) return value.forEach((item) => nativeActions(item, add));
  if (!value || typeof value !== 'object') return;
  for (const [key, item] of Object.entries(value)) {
    if (key === 'actions' && item && typeof item === 'object' && Object.hasOwn(item, 'can_archive')) Object.assign(item, add);
    else nativeActions(item, add);
  }
}
const count = (value) => JSON.stringify(value).split('"can_archive"').length - 1;

for (const [label, fixture, recorded, id, scope, check] of cases) {
  const observed = (add) => {
    const state = structuredClone(recorded);
    if (add) nativeActions(state, add);
    return {mode: 'http', [scope]: state};
  };
  test(`${label}: the recording has describe actions to check`, () => assert.ok(count(recorded) > 0));
  test(`${label}: native actions with a consistent redrive decision pass`, () => {
    for (const add of [{can_redrive: false, redrive_blocked_reason: 'run_not_failed'}, {can_redrive: true, redrive_blocked_reason: null}]) {
      assert.deepStrictEqual(check(fixture, observed(add), id, 'rust'), {scope});
    }
  });
  test(`${label}: native actions without the redrive decision are rejected`, () => {
    assert.throws(() => check(fixture, observed(), id, 'rust'), /native can_redrive/);
  });
  test(`${label}: an inconsistent redrive decision is rejected`, () => {
    assert.throws(() => check(fixture, observed({can_redrive: true, redrive_blocked_reason: 'run_not_failed'}), id, 'rust'),
      /exactly when nothing blocks redrive/);
  });
  test(`${label}: published PHP does not report a redrive decision`, () => {
    assert.throws(() => check(fixture, observed({can_redrive: false, redrive_blocked_reason: 'run_not_failed'}), id));
  });
}
