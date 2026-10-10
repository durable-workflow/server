import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {admissionObservation} from '../Support/ServerParityAdmissionObservation.mjs';

// Modeled examples exercise rejection of corrupt evidence; actual role
// qualification requires independent published PHP/native/embedded execution.
const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/admission-role-tokens.json', import.meta.url)));
const examples = ['http', 'embedded'].map(mode => admissionObservation(fixture, mode));
const check = raw => checkObservation(fixture, raw, 'admission-reference-auth-namespace');
test('modeled role-token adapters project equally', () => assert.deepStrictEqual(check(examples[0]), check(examples[1])));
const http = examples[0];
const request = (raw, id) => raw.admission.requests.find(receipt => receipt.id === id);
const mutations = {
  'legacy full-access bypass': raw => { request(raw, 'legacy_worker_bypass_disabled').status = 200; },
  'hierarchical admin role': raw => { request(raw, 'admin_worker_before_version_namespace').response.allowed_roles = ['worker', 'admin']; },
  'wrong original role': raw => { request(raw, 'worker_read_forbidden').response.role = 'admin'; },
  'role refusal leaks namespace': raw => { request(raw, 'worker_control_before_version_namespace').response.namespace = 'parity-roles-ghost'; },
  'role refusal leaks version': raw => { request(raw, 'operator_worker_before_version_namespace').response.requested_version = '999'; },
  'role refusal loses canonical diagnostic': raw => { request(raw, 'worker_read_forbidden').response.message = 'different'; },
  'authentication bypass': raw => { request(raw, 'invalid_worker_before_role_version_namespace').status = 403; },
  'operator positive read denied': raw => { request(raw, 'operator_read_allowed').status = 403; },
  'admin positive read denied': raw => { request(raw, 'admin_read_allowed').status = 403; },
  'legacy admin positive read denied': raw => { request(raw, 'legacy_admin_read_allowed').status = 403; },
  'wrong cleanup credential': raw => { raw.admission.cleanup_auth = 'worker'; },
  'replacement cleanup run': raw => { raw.admission.cleanup.run_id = 'replacement'; },
  'cleanup not committed': raw => { raw.admission.cleanup.outcome = 'pending'; },
};
for (const [name, mutate] of Object.entries(mutations)) test(name, () => {
  const raw = structuredClone(http);
  mutate(raw);
  assert.throws(() => check(raw));
});
for (const expected of fixture.admission.requests) test(`${expected.id} missing original receipt`, () => {
  const raw = structuredClone(http);
  raw.admission.requests = raw.admission.requests.filter(receipt => receipt.id !== expected.id);
  assert.throws(() => check(raw));
});
