import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';

// Reviewed external payload storage diagnostic (StorageController::test with
// Laravel's validation messages). The local driver stores content-addressed
// objects, so each round trip's reference identity is the digest of the
// object URI under the namespace's configured root. Embedded applications
// have no HTTP diagnostic, so the projection is the definition alone.
const sha256 = value => createHash('sha256').update(value).digest('hex');
const REQUIRED = field => `The ${field} field is required.`;
const INVALID_ERRORS = [
  {small_payload_bytes: [REQUIRED('small payload bytes')], large_payload_bytes: [REQUIRED('large payload bytes')]},
  {driver: ['The selected driver is invalid.'],
    small_payload_bytes: ['The small payload bytes field must be at least 1.'],
    large_payload_bytes: ['The large payload bytes field must not be greater than 16777216.']},
  {driver: ['The driver field must be a string.', 'The selected driver is invalid.'],
    small_payload_bytes: ['The small payload bytes field must be an integer.'],
    large_payload_bytes: ['The large payload bytes field must be an integer.']},
];

function validation(errors) {
  const messages = Object.values(errors).flat();
  const more = messages.length - 1;
  const message = messages[0] + (more === 0 ? '' : ` (and ${more} more ${more === 1 ? 'error' : 'errors'})`);
  return {status: 422, response: {message, reason: 'validation_failed', errors, validation_errors: errors}};
}

export function checkStorageTest(fixture, observation, workflowId) {
  const definition = fixture.storage_test;
  const state = observation.storage_test;
  const projection = {scope: 'storage_test', sizes: definition.sizes};
  assert.ok(state && typeof state === 'object', 'actual storage diagnostic observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_storage_diagnostic'},
      'embedded storage diagnostic explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const namespace = `${workflowId}-storage`.toLowerCase();
  equal([state.applicable, state.namespace], [true, namespace], 'diagnostic namespace');
  const failed = (reason, message, driver, extra = {}) => ({status: 422, response: {status: 'failed', reason, message,
    namespace, driver, ...extra}});
  equal(state.not_configured, failed('external_storage_not_configured',
    'External payload storage is not configured for this namespace.', null), 'no policy');
  equal([state.disabled_policy, state.enabled_policy], [200, 200], 'policies stored');
  equal(state.disabled, failed('external_storage_disabled', 'External payload storage is disabled for this namespace.',
    'local'), 'disabled policy');
  equal(state.other_driver, failed('storage_driver_unavailable',
    'The requested external payload storage driver is not configured for this namespace.', 's3',
    {supported_diagnostic_drivers: ['local']}), 'a driver other than the configured one');
  equal(definition.invalid.length, INVALID_ERRORS.length, 'every invalid body has reviewed messages');
  equal(state.invalid, INVALID_ERRORS.map(validation), 'Laravel validation messages');
  const roundTrip = (fill, bytes) => {
    const digest = sha256(fill.repeat(bytes));
    return {status: 'passed', bytes, sha256: digest,
      reference_identity_sha256: sha256(`file:///state/${namespace}/avro/${digest.slice(0, 2)}/${digest}`)};
  };
  const passed = {status: 200, response: {status: 'passed', namespace, driver: 'local',
    small_payload: roundTrip('s', definition.sizes.small_payload_bytes),
    large_payload: roundTrip('l', definition.sizes.large_payload_bytes)}};
  equal(state.passed, passed, 'a round trip through the local driver');
  equal(state.repeated, passed, 'the diagnostic can run again over the same objects');
  return projection;
}
