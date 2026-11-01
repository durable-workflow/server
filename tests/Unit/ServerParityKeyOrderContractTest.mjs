import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkKeyOrder} from '../../scripts/conformance/server-parity/key-order-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/key-order.json', import.meta.url), 'utf8'));
const expected = fixture.key_order.expected;
const observation = () => structuredClone({mode: 'http', key_order: {applicable: true, ...expected}});
const decode = blob => Buffer.from(blob, 'base64').toString('latin1');

test('the published answers pass', () => {
  assert.deepStrictEqual(checkKeyOrder(fixture, observation()), {scope: 'key_order'});
});

test('embedded applications have no HTTP request bodies', () => {
  assert.deepStrictEqual(checkKeyOrder(fixture,
    {mode: 'embedded', key_order: {applicable: false, reason: 'embedded_has_no_http_request_bodies'}}),
  {scope: 'key_order'});
});

test('plain input keeps document order', () => {
  const nested = decode(expected.starts.nested_map.input_blob);
  assert.ok(nested.indexOf('zeta') < nested.indexOf('alpha') && nested.indexOf('yak') < nested.indexOf('bee'),
    'nested maps are encoded in document order');
  assert.deepStrictEqual(expected.starts.object_integer_keys.input, ['a', 'b']);
  assert.deepStrictEqual(expected.starts.object_named_keys.input, ['b', 'a']);
  const signal = decode(expected.signal.arguments_blob);
  assert.ok(signal.indexOf('zeta') < signal.indexOf('alpha'), 'signal arguments keep document order');
  for (const name of ['nested_integer_keys', 'nested_integer_key']) {
    assert.deepStrictEqual([expected.starts[name].start.status, expected.starts[name].start.reason], [422, 'validation_failed']);
  }
});

test('several invalid search attributes are reported in request order', () => {
  const keys = messages => messages.map(message => message.match(/\[(\w+)\]/)[1]);
  assert.deepStrictEqual(keys(expected.starts.search_attribute_errors.start.errors.search_attributes), ['Zz', '9bad', 'Aa']);
  assert.deepStrictEqual(keys(expected.upsert.errors['commands.0.attributes']), ['Zz', '9bad', 'Aa']);
});

for (const [name, mutate] of [
  ['a nested map blob with another key order', state => {
    state.starts.nested_map.input_blob = 'wwHioz3/VYAiNwwEDgQKYWxwaGEOBAZiZWUEBgZ5YWsEBAAIemV0YQQCAAoCeAA=';
  }],
  ['object input values in sorted key order', state => { state.starts.object_named_keys.input = ['a', 'b']; }],
  ['out-of-order integer keys read as a list', state => {
    state.starts.nested_integer_keys = {start: {status: 201, reason: null, outcome: 'started_new', message: null, errors: null},
      input: [['b', 'a']], input_blob: 'x'};
  }],
  ['search attribute errors in sorted key order', state => {
    state.upsert.errors['commands.0.attributes'].reverse();
  }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.key_order);
  assert.throws(() => checkKeyOrder(fixture, raw));
});
