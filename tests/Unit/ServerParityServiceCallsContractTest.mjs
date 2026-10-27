import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkServiceCalls} from '../../scripts/conformance/server-parity/service-calls-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/service-calls.json', import.meta.url)));
const id = 'parity-v1-service-calls';
const own = `${id}-calls`;

// The recorded answers with each placeholder given a concrete value: one per
// name, a fresh one for each unnamed placeholder.
const observation = () => {
  const values = new Map();
  let fresh = 0;
  const generate = kind => {
    const index = String(fresh++).padStart(4, '0');
    return {
      id: `01m5wvy8d1j3dtr13es05k${index}`,
      ULID: `01M5WVY8D1J3DTR13ES05K${index}`,
      time: `2026-10-26T19:${index.slice(2)}:00+00:00`,
      'µs': `2026-10-26T19:${index.slice(2)}:00.123456Z`,
    }[kind];
  };
  const concrete = value => {
    if (typeof value === 'string') {
      return value.split('@own').join(own).replace(/\{(id|ULID|time|µs)(?::([^{}]+))?\}/gu, (_, kind, name) => {
        if (name === undefined) {
          return generate(kind);
        }
        const key = `${kind}:${name}`;
        if (!values.has(key)) {
          values.set(key, generate(kind));
        }
        return values.get(key);
      });
    }
    if (Array.isArray(value)) {
      return value.map(concrete);
    }
    if (value !== null && typeof value === 'object') {
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, concrete(item)]));
    }
    return value;
  };
  const responses = {};
  for (const {label} of fixture.service_calls.requests) {
    responses[label] = concrete(fixture.service_calls.expected[label]);
  }
  return {mode: 'http', service_calls: {applicable: true, namespace: own, responses}};
};

test('the service calls accept the published answers', () => {
  assert.deepStrictEqual(checkServiceCalls(fixture, observation(), id), {scope: 'service_calls', cases: 90});
});

test('the fixture covers execute, show, cancel and every reachable refusal', () => {
  const {expected} = fixture.service_calls;
  const statuses = new Set(Object.values(expected).map(([status]) => status));
  assert.deepStrictEqual([...statuses].sort(), [200, 201, 403, 404, 409, 422, 429, 503]);
  assert.equal(expected['expired'][1].call_deadline_at, '2001-02-03T03:05:06.500000Z');
  assert.equal(expected['broken retry'][1].service_call_attempts.length, 3);
  assert.equal(expected['replay again'][1].idempotent_replay, true);
  assert.deepStrictEqual([expected['rated again'][1].reason, expected['held again'][1].reason, expected['held sync again'][0]],
    ['rate_limit_exceeded', 'concurrency_limit_exceeded', 200]);
  assert.equal(fixture.service_calls.requests.find(request => request.label === 'rated').minute_start, true);
  assert.deepStrictEqual([expected['catalog calls of charge'][1].total, expected['catalog policy calls page'][1].total,
    expected['catalog show failed call'][1].failure_type], [3, 8, 'handler_target_missing']);
});

test('the embedded service calls are explicitly inapplicable', () => {
  checkServiceCalls(fixture, {mode: 'embedded', service_calls: {applicable: false, reason: 'embedded_has_no_http_service_calls'}}, id);
  assert.throws(() => checkServiceCalls(fixture, {mode: 'embedded', service_calls: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['a route that is not implemented', responses => { responses.charge = [501, {reason: 'rust_capability_not_implemented'}]; }],
  ['a lowercase handler id', responses => { responses.charge[1].resolved_target_reference = responses.charge[1].resolved_target_reference.toLowerCase(); }],
  ['a whole-second lifecycle time', responses => { responses.charge[1].accepted_at = '2026-10-26T19:00:00+00:00'; }],
  ['an unredacted forbidden answer', responses => { responses['need role'][1].message = 'Caller [legacy-token] is missing one of the required roles for service-boundary admission.'; }],
  ['a redacted audit row', responses => { responses['show need role'][1].outcome_metadata = {failure_reason: 'policy_rejection'}; }],
  ['a 202 for an accepted call', responses => { responses.charge[0] = 202; }],
  ['a rejected past deadline', responses => { responses.expired[1].accepted = false; }],
  ['an idempotent cancel', responses => { responses['cancel charge again'] = [200, responses['cancel charge'][1]]; }],
  ['a replay in the controller shape', responses => { responses['replay again'][1].id = responses['replay again'][1].service_call_id; }],
  ['a replayed forbidden call that reuses the id', responses => {
    responses['replay forbidden'][1].service_call_id = responses['replay second key'][1].id;
  }],
  ['a rate limit that never refuses', responses => { responses['rated again'] = [200, responses.rated[1]]; }],
  ['a sync lease that is never returned', responses => { responses['held sync again'] = [429, responses['held again'][1]]; }],
  ['a refusal that counts the refused call', responses => { responses['held again'][1].outcome_metadata.observed_in_flight = 2; }],
  ['a calls list that is always empty', responses => {
    Object.assign(responses['catalog calls of charge'][1], {data: [], total: 0, last_page: 1});
  }],
  ['calls listed oldest first', responses => { responses['catalog calls of charge'][1].data.reverse(); }],
  ['a caller scope that keeps another caller', responses => {
    responses['catalog calls of charge as caller'][1].data.push(responses['catalog calls of charge'][1].data[1]);
  }],
  ['a whole-second list time', responses => { responses['catalog cancelled calls'][1].data[0].created_at = '2026-10-26T19:00:00+00:00'; }],
  ['a caller link read as the observer', responses => {
    responses['catalog show call with caller'][1].caller_link.in_observer_namespace = true;
  }],
  ['a message naming another call', responses => {
    responses['show under another operation'][1].message = responses['show under another operation'][1].message.replace(/\[01[^\]]+\]/u, '[01m5wvy8d1j3dtr13es05k9999]');
  }],
]) {
  test(`the service calls reject ${label}`, () => {
    const value = observation();
    mutate(value.service_calls.responses);
    assert.throws(() => checkServiceCalls(fixture, value, id));
  });
}
