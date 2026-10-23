import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkRepairPass, repairPassCases} from '../../scripts/conformance/server-parity/repair-pass-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/repair-pass.json', import.meta.url)));
const definition = fixture.repair_pass;
const id = 'parity-v1-repair-pass';
const own = `${id}-repair`;

const observation = () => {
  const responses = {};
  for (const [label, , [status, response]] of repairPassCases(fixture, own)) {
    responses[label] = {status, response: structuredClone(response)};
  }
  return {mode: 'http', repair_pass: {applicable: true, namespace: own, responses}};
};

test('the repair pass accepts the published answers', () => {
  assert.deepStrictEqual(checkRepairPass(fixture, observation(), id), {scope: 'repair_pass', cases: 9});
});

test('the fixture scopes every pass away from other work', () => {
  for (const [label, body] of definition.requests) {
    assert.ok(Array.isArray(body.run_ids) || typeof body.run_ids === 'string', `${label} names run ids`);
    assert.ok(typeof body.queue !== 'string' || body.queue.includes('@own') || body.queue.length > 128, `${label} names its own queue`);
  }
  const passes = Object.values(definition.expected).filter(([status]) => status === 200);
  assert.equal(passes.length, 3);
  for (const [, report] of passes) {
    assert.equal(report.selected_total_candidates, 0);
    assert.equal(report.throttled, false);
  }
});

test('the placeholders become the probe namespace', () => {
  const [, body, [, report]] = repairPassCases(fixture, own).find(([label]) => label === 'trimmed filters');
  assert.equal(body.instance_id, ` ${own}-instance `);
  assert.equal(report.instance_id, `${own}-instance`);
  assert.ok(!JSON.stringify(repairPassCases(fixture, own)).includes('@own'));
});

test('the embedded repair pass is explicitly inapplicable', () => {
  checkRepairPass(fixture, {mode: 'embedded', repair_pass: {applicable: false, reason: 'embedded_has_no_http_system_routes'}}, id);
  assert.throws(() => checkRepairPass(fixture, {mode: 'embedded', repair_pass: {applicable: true}}, id));
});

const report = (state, label) => state.responses[label].response;
for (const [label, mutate] of [
  ['an unimplemented route', state => { state.responses['filtered pass'] = {status: 501, response: {reason: 'rust_capability_not_implemented'}}; }],
  ['a missing request', state => { delete state.responses['queue too long']; }],
  ['another namespace', state => { state.namespace = 'default'; }],
  ['untrimmed filters', state => { report(state, 'trimmed filters').queue = ` ${own}-queue `; }],
  ['an empty instance kept as text', state => { report(state, 'empty instance').instance_id = ''; }],
  ['a throttled pass', state => { report(state, 'filtered pass').throttled = true; }],
  ['a selected candidate', state => { report(state, 'filtered pass').selected_total_candidates = 1; }],
  ['a missing service call count', state => { delete report(state, 'filtered pass').service_call_timeout_candidates; }],
  ['a partial pass', state => { state.responses['filtered pass'].status = 207; }],
  ['an accepted text throttle', state => { state.responses['throttle not boolean'] = {status: 200, response: {}}; }],
  ['another run id message', state => { report(state, 'empty run id').errors['run_ids.0'] = ['The run_ids.0 field must be a string.']; }],
  ['an accepted run id list over 100', state => { state.responses['too many run ids'].status = 200; }],
]) {
  test(`the repair pass rejects ${label}`, () => {
    const value = observation();
    mutate(value.repair_pass);
    assert.throws(() => checkRepairPass(fixture, value, id));
  });
}
