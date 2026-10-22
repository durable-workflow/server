import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkPayloadCleanup} from '../../scripts/conformance/server-parity/payload-cleanup-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/payload-cleanup.json', import.meta.url)));
const definition = fixture.payload_cleanup;
const id = 'parity-v1-payload-cleanup';
const own = `${id}-cleanup`;

const observation = () => {
  const requests = {};
  for (const [label] of definition.requests) {
    const [status, body] = definition.expected[label];
    requests[label] = {status, response: JSON.parse(JSON.stringify(body).split('"@own"').join(JSON.stringify(own))
      .split('"@second"').join('"2026-10-22T23:08:38.000000Z"'))};
  }
  return {mode: 'http', payload_cleanup: {applicable: true, namespace: own, requests}};
};

test('the payload cleanup accepts the published answers', () => {
  assert.deepStrictEqual(checkPayloadCleanup(fixture, observation(), id), {scope: 'payload_cleanup', cases: 12});
});

test('the fixture covers status, passes and limit validation', () => {
  const statuses = Object.values(definition.expected).map(([status]) => status);
  assert.deepStrictEqual([...new Set(statuses)].sort(), [200, 422]);
  assert.equal(definition.expected['status final'][1].cleanup.totals.passes, 5);
  assert.equal(definition.expected['status fresh'][1].cleanup.last_pass.status, null);
});

test('the embedded payload cleanup is explicitly inapplicable', () => {
  checkPayloadCleanup(fixture, {mode: 'embedded', payload_cleanup: {applicable: false, reason: 'embedded_has_no_http_system_routes'}}, id);
  assert.throws(() => checkPayloadCleanup(fixture, {mode: 'embedded', payload_cleanup: {applicable: true}}, id));
});

const response = (state, label) => state.requests[label].response;
for (const [label, mutate] of [
  ['an unimplemented route', state => { state.requests.pass = {status: 501, response: {reason: 'rust_capability_not_implemented'}}; }],
  ['a missing request', state => { delete state.requests['pass limit 5']; }],
  ['another namespace', state => { response(state, 'pass').namespace = 'default'; }],
  ['a pass that is not recorded', state => { response(state, 'status after pass').cleanup.totals.passes = 0; }],
  ['a refused pass counted', state => { response(state, 'status final').cleanup.totals.passes = 9; }],
  ['a millisecond completion time', state => { response(state, 'status final').cleanup.last_pass.completed_at = '2026-10-22T23:08:38.123000Z'; }],
  ['a missing completion time', state => { response(state, 'status after pass').cleanup.last_pass.completed_at = null; }],
  ['an ignored limit', state => { response(state, 'pass limit 5').cleanup.scan_limit = 100; }],
  ['an accepted fractional limit', state => { state.requests['pass limit fraction'] = {status: 200, response: {}}; }],
  ['a refused null limit', state => { state.requests['pass limit null'].status = 422; }],
  ['a min message for the maximum', state => { response(state, 'pass limit 1001').message = 'The limit field must be at least 1.'; }],
  ['a pass that reports pressure', state => { response(state, 'pass').cleanup.scan_pressure = true; }],
  ['another expiry bound', state => { response(state, 'status fresh').cleanup.bounds.abandoned_upload_expiry_seconds = 60; }],
  ['an exposed storage location', state => { response(state, 'status fresh').cleanup.identity_policy.provider_locations_exposed = true; }],
]) {
  test(`the payload cleanup rejects ${label}`, () => {
    const value = observation();
    mutate(value.payload_cleanup);
    assert.throws(() => checkPayloadCleanup(fixture, value, id));
  });
}
