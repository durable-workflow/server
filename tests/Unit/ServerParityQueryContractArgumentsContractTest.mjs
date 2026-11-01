import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkQueryContractArguments} from '../../scripts/conformance/server-parity/query-contract-arguments-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/query-contract-arguments.json', import.meta.url), 'utf8'));
const definition = fixture.query_contract_arguments;
const expected = definition.expected;
const observation = () => structuredClone({mode: 'http', query_contract_arguments: {applicable: true, ...expected}});
const cases = Object.keys(definition.cases).length;

test('the published answers pass', () => {
  assert.deepStrictEqual(checkQueryContractArguments(fixture, observation()), {scope: 'query_contract_arguments', cases});
});

test('an unencodable default pins only the 500 status, so a server may explain it', () => {
  const raw = observation();
  raw.query_contract_arguments.mixed_default = {status: 500, reason: 'query_default_unencodable',
    message: 'Workflow query [mixeddef] parameter [value] has a registered default with an integer key outside a list, which the Avro Value codec cannot encode.',
    validation_errors: null};
  assert.deepStrictEqual(checkQueryContractArguments(fixture, raw), {scope: 'query_contract_arguments', cases});
});

test('defaults keep the key order the database stores', () => {
  // SQLite and Postgres keep the registered order. A MySQL json column orders
  // keys by length, then bytes, so the second default arrives as the first,
  // and the descending integer keys arrive as a list.
  assert.notStrictEqual(expected.registered_order_default.query_arguments.blob, expected.stored_order_default.query_arguments.blob);
  assert.deepStrictEqual(expected.descending_default, {leased: false, query_arguments: null, client: {status: 500, reason: null}});
  const mysql = observation();
  mysql.query_contract_arguments.stored_order_default = structuredClone(expected.registered_order_default);
  mysql.query_contract_arguments.descending_default = structuredClone(expected.list_default);
  assert.deepStrictEqual(checkQueryContractArguments(fixture, mysql), {scope: 'query_contract_arguments', cases});
  const explained = observation();
  explained.query_contract_arguments.descending_default.client.reason = 'query_default_unencodable';
  assert.deepStrictEqual(checkQueryContractArguments(fixture, explained), {scope: 'query_contract_arguments', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkQueryContractArguments(fixture,
    {mode: 'embedded', query_contract_arguments: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'query_contract_arguments', cases});
  assert.throws(() => checkQueryContractArguments(fixture, {mode: 'embedded', query_contract_arguments: {applicable: true}}));
});

test('accepted queries reach the worker as normalized positional lists', () => {
  const blob = name => definition.blobs[name];
  assert.deepStrictEqual(expected.named.query_arguments.blob, blob('positional'));
  assert.deepStrictEqual(expected.positional.query_arguments.blob, blob('positional'));
  assert.deepStrictEqual(expected.default.query_arguments.blob, blob('default_filled'));
  assert.deepStrictEqual(expected.default_named.query_arguments.blob, blob('positional'));
  assert.deepStrictEqual(expected.plain_list.query_arguments.blob, blob('too_many'));
  assert.deepStrictEqual(expected.nested_object.query_arguments.blob, blob('one_empty_map'));
  assert.deepStrictEqual(expected.nested_variadic.query_arguments.blob, blob('one_empty_map'));
  assert.deepStrictEqual(expected.unsorted_variadic.query_arguments.blob, blob('spread_in_order'));
  for (const name of ['empty_map', 'numeric_map', 'mixed_map']) {
    assert.match(expected[name].message, /must decode to an array\.$/, name);
  }
  for (const name of ['named_extra', 'unknown', 'wrong_type', 'too_many', 'null', 'missing', 'plain_named', 'nested_array']) {
    assert.deepStrictEqual([expected[name].status, expected[name].reason], [422, 'invalid_query_arguments'], name);
    assert.match(expected[name].message, /argument validation failed\.$/, name);
  }
});

for (const [name, mutate] of [
  ['a named map refused as not a list', state => {
    state.named = {status: 422, reason: 'invalid_query_arguments', message: 'Workflow query [state] arguments must decode to an array.',
      validation_errors: {arguments: ['Query arguments must decode to an array.']}};
  }],
  ['the client blob passed through un-normalized', state => { state.named.query_arguments.blob = definition.blobs.named; }],
  ['a default left unfilled', state => { state.default.query_arguments.blob = definition.blobs.empty; }],
  ['a bare refusal', state => { state.wrong_type.message = 'invalid_query_arguments'; state.wrong_type.validation_errors = null; }],
  ['a missing unknown-argument message', state => { delete state.unknown.validation_errors.other; }],
  ['an empty map taken as an empty list', state => {
    state.empty_map = {leased: true, query_arguments: {codec: 'avro', blob: definition.blobs.default_filled}, answered: 200,
      client: {status: 200, reason: null}};
  }],
  ['a nested empty map accepted as an array', state => {
    state.nested_array = {leased: true, query_arguments: {codec: 'avro', blob: definition.blobs.one_empty_map}, answered: 200,
      client: {status: 200, reason: null}};
  }],
  ['a nested empty map spread to nothing', state => { state.nested_variadic.query_arguments.blob = definition.blobs.empty; }],
  ['a variadic map spread in sorted key order', state => {
    state.unsorted_variadic.query_arguments.blob = 'wwHioz3/VYAiNwwEBBQEKAA=';
  }],
  ['a list default routed as a map', state => {
    state.list_default.query_arguments.blob = 'wwHioz3/VYAiNwwCDgQCMAoCeAIxCgJ5AAA=';
  }],
  ['an unencodable default routed', state => {
    state.mixed_default = {status: 200, reason: null, message: null, validation_errors: null};
  }],
  ['an unencodable default refused as invalid arguments', state => {
    state.gap_default = {status: 422, reason: 'invalid_query_arguments', message: 'x', validation_errors: {value: ['x']}};
  }],
  ['a list default sent as a JSON array', state => { state.sent_defaults.listdef = '["x","y"]'; }],
  ['a map default in sorted key order', state => {
    state.registered_order_default.query_arguments.blob = expected.stored_order_default.query_arguments.blob;
  }],
  ['a kept order with descending integer keys routed as a list', state => {
    state.descending_default = structuredClone(expected.list_default);
  }],
  ['a reordered database with descending integer keys refused', state => {
    state.stored_order_default = structuredClone(expected.registered_order_default);
  }],
  ['a stored-order default routed in neither order', state => {
    state.stored_order_default.query_arguments.blob = expected.list_default.query_arguments.blob;
  }],
  ['descending integer keys refused as invalid arguments', state => {
    state.descending_default = {leased: false, query_arguments: null, client: {status: 422, reason: 'invalid_query_arguments'}};
  }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.query_contract_arguments);
  assert.throws(() => checkQueryContractArguments(fixture, raw));
});
