import test from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {checkStorageTest} from '../../scripts/conformance/server-parity/storage-test-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/storage-test.json', import.meta.url)));
const namespace = 'test-storage';
const root = `file:///state/storage-test-parity-rust/${namespace}`;
const sha256 = value => createHash('sha256').update(value).digest('hex');
const invalid = errors => {
  const messages = Object.values(errors).flat();
  const more = messages.length - 1;
  return {status: 422, response: {message: messages[0] + (more ? ` (and ${more} more error${more === 1 ? '' : 's'})` : ''),
    reason: 'validation_failed', errors, validation_errors: errors}};
};
const failed = (reason, message, driver, extra = {}) =>
  ({status: 422, response: {status: 'failed', reason, message, namespace, driver, ...extra}});
const roundTrip = (fill, bytes) => {
  const digest = sha256(fill.repeat(bytes));
  return {status: 'passed', bytes, sha256: digest,
    reference_identity_sha256: sha256(`${root}/avro/${digest.slice(0, 2)}/${digest}`)};
};
function model() {
  const passed = {status: 200, response: {status: 'passed', namespace, driver: 'local',
    small_payload: roundTrip('s', 64), large_payload: roundTrip('l', 70000)}};
  return structuredClone({mode: 'http', storage_test: {applicable: true, namespace, storage_root: root,
    not_configured: failed('external_storage_not_configured', 'External payload storage is not configured for this namespace.', null),
    disabled_policy: 200,
    disabled: failed('external_storage_disabled', 'External payload storage is disabled for this namespace.', 'local'),
    enabled_policy: 200,
    other_driver: failed('storage_driver_unavailable',
      'The requested external payload storage driver is not configured for this namespace.', 's3',
      {supported_diagnostic_drivers: ['local']}),
    invalid: [
      invalid({small_payload_bytes: ['The small payload bytes field is required.'],
        large_payload_bytes: ['The large payload bytes field is required.']}),
      invalid({driver: ['The selected driver is invalid.'],
        small_payload_bytes: ['The small payload bytes field must be at least 1.'],
        large_payload_bytes: ['The large payload bytes field must not be greater than 16777216.']}),
      invalid({driver: ['The driver field must be a string.', 'The selected driver is invalid.'],
        small_payload_bytes: ['The small payload bytes field must be an integer.'],
        large_payload_bytes: ['The large payload bytes field must be an integer.']}),
    ],
    passed, repeated: passed}});
}
const projection = {scope: 'storage_test', sizes: {small_payload_bytes: 64, large_payload_bytes: 70000}};
test('published storage diagnostic behavior passes', () =>
  assert.deepStrictEqual(checkStorageTest(fixture, model(), 'TEST'), projection));
test('embedded explicitly declares the HTTP diagnostic inapplicable', () => assert.deepStrictEqual(checkStorageTest(fixture,
  {mode: 'embedded', storage_test: {applicable: false, reason: 'embedded_has_no_http_storage_diagnostic'}}, 'test'), projection));
test('rejects an embedded observation that runs the diagnostic', () => assert.throws(() => checkStorageTest(fixture,
  {...model(), mode: 'embedded'}, 'test')));
for (const [label, mutate] of [
  ['a missing observation', s => { for (const key of Object.keys(s)) delete s[key]; }],
  ['another namespace', s => s.namespace = 'default'],
  ['a root shared with another target', s => s.storage_root = `file:///state/${namespace}`],
  ['a root outside the namespace', s => s.storage_root = 'file:///state/storage-test-parity-rust/default'],
  ['no recorded root', s => delete s.storage_root],
  ['a diagnostic without a policy that passes', s => s.not_configured = s.passed],
  ['a refusal without its driver', s => delete s.not_configured.response.driver],
  ['a policy refused', s => s.disabled_policy = 422],
  ['a disabled policy that runs', s => s.disabled = {status: 200, response: s.passed.response}],
  ['another driver accepted', s => s.other_driver.status = 200],
  ['supported drivers omitted', s => delete s.other_driver.response.supported_diagnostic_drivers],
  ['a validation message changed', s => s.invalid[2].response.errors.driver.pop()],
  ['the summary miscounts errors', s => s.invalid[0].response.message = 'The small payload bytes field is required.'],
  ['validation errors without the legacy field', s => delete s.invalid[1].response.validation_errors],
  ['an invalid body accepted', s => s.invalid[1] = s.passed],
  ['an invalid body missing', s => s.invalid.pop()],
  ['a payload of another size', s => s.passed.response.large_payload.bytes = 69999],
  ['a digest of other bytes', s => s.passed.response.small_payload.sha256 = sha256('x'.repeat(64))],
  ['a reference outside the namespace root', s => s.passed.response.small_payload.reference_identity_sha256 = sha256('file:///state/other')],
  ['a failed round trip', s => s.passed.response.large_payload.status = 'failed'],
  ['a repeated diagnostic refused', s => s.repeated = failed('storage_round_trip_failed', 'x', 'local')],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation.storage_test);
    assert.throws(() => checkStorageTest(fixture, observation, 'TEST'));
  });
}
