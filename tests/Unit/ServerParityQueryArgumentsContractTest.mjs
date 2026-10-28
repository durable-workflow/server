import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkQueryArgumentRouting} from '../../scripts/conformance/server-parity/query-arguments-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/query-arguments.json', import.meta.url), 'utf8'));
const expected = fixture.query_argument_routing.expected;
const observation = () => structuredClone({mode: 'http', query_argument_routing: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkQueryArgumentRouting(fixture, observation()), {scope: 'query_argument_routing', runs: 2});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkQueryArgumentRouting(fixture,
    {mode: 'embedded', query_argument_routing: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'query_argument_routing', runs: 2});
  assert.throws(() => checkQueryArgumentRouting(fixture, {mode: 'embedded', query_argument_routing: {applicable: true}}));
});

test('an uncontracted run routes the blob; a declared query refuses it', () => {
  for (const blob of ['undecodable', 'not_a_list']) {
    const routed = expected.uncontracted[blob];
    assert.deepStrictEqual([routed.leased, routed.arguments.blob, routed.answered, routed.client.status],
      [true, fixture.query_argument_routing.blobs[blob], 200, 200], blob);
    const refused = expected.contracted[blob];
    assert.deepStrictEqual([refused.status, refused.reason], [422, 'invalid_query_arguments'], blob);
  }
});

for (const [name, mutate] of [
  ['an undecodable blob refused before routing', state => {
    state.uncontracted.undecodable = {leased: false, arguments: null,
      client: {status: 422, reason: 'invalid_query_arguments'}};
  }],
  ['a bare refusal without its message', state => {
    state.contracted.undecodable.message = 'invalid_query_arguments';
    state.contracted.undecodable.validation_errors = null;
  }],
  ['a re-encoded blob', state => { state.uncontracted.not_a_list.arguments.blob = 'wwHioz3/VYAiNwwA'; }],
  ['an unexpected run', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.query_argument_routing);
  assert.throws(() => checkQueryArgumentRouting(fixture, raw));
});
