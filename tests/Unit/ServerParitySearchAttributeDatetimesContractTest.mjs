import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSearchAttributeDatetimes} from '../../scripts/conformance/server-parity/search-attribute-datetimes-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/search-attribute-datetimes.json', import.meta.url), 'utf8'));
const definition = fixture.search_attribute_datetimes;
const expected = definition.expected;
const observation = () => structuredClone({mode: 'http', search_attribute_datetimes: {applicable: true, ...expected}});
const cases = Object.keys(definition.values).length * 2 + Object.keys(definition.limits).length;

test('the published answers pass', () => {
  assert.deepStrictEqual(checkSearchAttributeDatetimes(fixture, observation()), {scope: 'search_attribute_datetimes', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkSearchAttributeDatetimes(fixture,
    {mode: 'embedded', search_attribute_datetimes: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'search_attribute_datetimes', cases});
});

test('an offset is stored as the instant it names, on a start and an upsert', () => {
  for (const path of ['start', 'upsert']) {
    for (const name of ['offset_extended', 'offset_basic', 'offset_hours', 'space_offset', 'lowercase', 'rfc2822']) {
      assert.deepStrictEqual(expected[path][name].described, {When: '2026-10-08T10:34:56.000000Z'}, `${path} ${name}`);
    }
    assert.deepStrictEqual(expected[path].offset_negative_micros.described, {When: '2026-10-08T18:04:56.123456Z'});
    assert.deepStrictEqual(expected[path].utc_truncated.described, {When: '2026-10-08T12:34:56.999999Z'});
    assert.deepStrictEqual(expected[path].naive.described, {When: '2026-10-08T12:34:56.000000Z'});
  }
  assert.equal(expected.filtered.workflow_ids.length, 12);
});

test('the limits accept their boundary and reject one more', () => {
  assert.deepStrictEqual([expected.limits.at_count_limit.completed.status, expected.limits.at_count_limit.described], [200, 100]);
  assert.deepStrictEqual([expected.limits.at_size_limit.completed.status, expected.limits.at_size_limit.described], [200, 32]);
  for (const name of ['over_count_limit', 'over_size_limit']) {
    assert.deepStrictEqual([expected.limits[name].completed.status, expected.limits[name].completed.reason, expected.limits[name].event],
      [422, 'invalid_workflow_stream_command', 'absent'], name);
  }
});

for (const [name, mutate] of [
  ['an offset stored as its wall-clock time', state => { state.upsert.offset_basic.described.When = '2026-10-08T12:34:56.000000Z'; }],
  ['nanoseconds kept past microseconds', state => { state.start.utc_nanoseconds.described.When = '2026-10-08T12:34:56.123456789Z'; }],
  ['a refused RFC 2822 date', state => { state.upsert.rfc2822.completed.status = 422; }],
  ['the previous native limit refusal', state => { state.limits.over_count_limit.completed.reason = 'search_attribute_limit_exceeded'; }],
  ['a missing filtered run', state => { state.filtered.workflow_ids.pop(); }],
  ['an unexpected step', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.search_attribute_datetimes);
  assert.throws(() => checkSearchAttributeDatetimes(fixture, raw));
});
