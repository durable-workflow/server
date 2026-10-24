import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkUpdateValidation} from '../../scripts/conformance/server-parity/update-validation-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/update-validation.json', import.meta.url)));
// Published PHP 2.11.1's recorded probe output.
const recorded = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityExpected/update-validation-observation.json', import.meta.url)));
const workflowId = 'uv-probe-3';
const observation = () => ({mode: 'http', update_validation: structuredClone(recorded)});
const projection = {scope: 'update_validation', steps: Object.keys(fixture.update_validation.expected).length};

test('published PHP passes', () => {
  assert.deepStrictEqual(checkUpdateValidation(fixture, observation(), workflowId), projection);
});

test('embedded explicitly declares update validation inapplicable', () => {
  assert.deepStrictEqual(checkUpdateValidation(fixture, {mode: 'embedded',
    update_validation: {applicable: false, reason: 'embedded_has_no_http_update_validation'}}, workflowId), projection);
  assert.throws(() => checkUpdateValidation(fixture, {mode: 'embedded', update_validation: {applicable: true}}, workflowId));
});

test('other identifiers, timestamps and requesters pass', () => {
  const text = JSON.stringify(recorded);
  const ids = [...new Set(text.match(/"[0-9a-z]{26}"/g))];
  let renamed = text;
  ids.forEach((id, index) => {
    renamed = renamed.replaceAll(id, `"01NATIVE${String(index).padStart(18, '0')}"`);
  });
  renamed = renamed.replace(/"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z"/g, '"2030-01-01T00:00:00.000000Z"')
    .replaceAll('"legacy-token"', '"role:operator"');
  assert.deepStrictEqual(checkUpdateValidation(fixture, {mode: 'http', update_validation: JSON.parse(renamed)}, workflowId),
    projection);
});

for (const [label, mutate, message] of [
  ['an update id other than its validation task\'s', state => { state.approved_update.response.update_id = '01zzzzzzzzzzzzzzzzzzzzzzzz'; }],
  ['a retry with a new receipt', state => { state.retained_approval.response.command_id = '01zzzzzzzzzzzzzzzzzzzzzzzz'; }],
  ['a rejection recorded as accepted', state => { state.rejected_update.status = 202; }],
  ['a missing approved flag on a failure', state => { delete state.unclaimed.response.approved; }],
  ['a timeout that did not wait', state => { state.unclaimed.seconds = 0.4; }, /waited for the validation timeout/],
  ['a timeout that never ended', state => { state.worker_lost.seconds = 60; }, /waited for the validation timeout/],
  ['another timeout reason', state => { state.execution_timeout.response.reason = 'update_validation_task_not_claimed'; }],
  ['an approval after a lost worker', state => { state.lost_approve.status = 200; }],
  ['an expired lease approved', state => { state.lease_expired.status = 200; }],
  ['a re-claim without a new attempt', state => { state.reclaimed.attempt = 1; }],
  ['a multiplexed poll that always takes workflow tasks first', state => { state.multiplexed_order = ['workflow', 'update_validation']; }],
  ['a validation task without its history export', state => {
    state.dedicated_poll.task.keys = state.dedicated_poll.task.keys.filter(key => key !== 'history_export');
  }],
  ['a multiplexed validation task without its page token', state => {
    state.multiplexed_poll.task.keys = state.multiplexed_poll.task.keys.filter(key => key !== 'next_history_page_token');
  }],
  ['an update refused without a contract statement', state => { state.contract_missing.status = 202; }],
  ['retryable routing refusals reported final', state => { state.worker_incompatible.response.retryable = false; }],
  ['a rejection event without its errors', state => { state.history[3][2] = null; }],
  ['a missing step', state => { delete state.plain; }, /steps/],
  ['another workflow', state => { state.workflow_id = 'other-uv'; }, /workflow/],
  ['a capability that is not advertised', state => { state.capability[0] = false; }],
]) {
  test(`rejects ${label}`, () => {
    const actual = observation();
    mutate(actual.update_validation);
    assert.throws(() => checkUpdateValidation(fixture, actual, workflowId), message);
  });
}
