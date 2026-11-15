import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkNamespaceDeletePayloads} from '../../scripts/conformance/server-parity/namespace-delete-payloads-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/namespace-delete-payloads.json', import.meta.url)));
const definition = fixture.namespace_delete_payloads;
const id = 'parity-v1-namespace-delete-payloads';
const namespace = `${id}-nsdelete`;

const observation = () => ({mode: 'http', namespace_delete_payloads: {applicable: true, namespace,
  peer: `${namespace}-peer`, idle: `${namespace}-idle`,
  storage_root: `file:///state/namespace-delete-payloads-parity-server/${namespace}`,
  setup: structuredClone(definition.setup), steps: structuredClone(definition.steps)}});

test('the namespace delete payloads accept the published answers', () => {
  assert.deepStrictEqual(checkNamespaceDeletePayloads(fixture, observation(), id),
    {scope: 'namespace_delete_payloads', steps: Object.keys(definition.steps)});
});

test('the fixture refuses without storage, then deletes and keeps', () => {
  const steps = definition.steps;
  assert.deepStrictEqual(Object.values(steps).map(step => step.status), [503, 200, 200, 404, 200, 200, 200]);
  assert.deepStrictEqual(steps.deleted.payloads, {external_payloads_deleted: 1, runtime_external_payload_references: 2});
  assert.deepStrictEqual(steps['peer fetched'], {status: 200, bytes_match: true});
  assert.equal(steps['storage off: refused'].body.reason, 'external_payload_storage_driver_unavailable');
  assert.deepStrictEqual(steps['idle deleted'].payloads, {runtime_external_payload_references: 1});
});

test('native deletion uses retained bindings exactly once after write policy is disabled', () => {
  const native = observation();
  const expected = JSON.parse(JSON.stringify(definition.native_expected).replaceAll('{namespace}', namespace));
  Object.assign(native.namespace_delete_payloads.setup, expected.setup);
  Object.assign(native.namespace_delete_payloads.steps, expected.steps);
  assert.deepStrictEqual(checkNamespaceDeletePayloads(fixture, native, id, 'rust'),
    checkNamespaceDeletePayloads(fixture, observation(), id, 'php'));
  assert.throws(() => checkNamespaceDeletePayloads(fixture, observation(), id, 'rust'));
  assert.throws(() => checkNamespaceDeletePayloads(fixture, native, id, 'php'));
  for (const mutate of [
    state => { state.setup.storage_on = 200; },
    state => { state.steps['storage off: refused'].payloads.external_payloads_deleted = 0; },
    state => { state.steps['storage off: refused'].payloads.runtime_external_payload_references = 1; },
    state => { state.steps.deleted = structuredClone(state.steps['storage off: refused']); },
    state => { state.steps.deleted.namespace_matches = false; },
    state => { state.steps['peer fetched'] = {status: 404, reason: 'external_payload_not_found'}; },
    state => { state.steps['storage off: refused'].cleanup.pending = 1; },
  ]) {
    const invalid = structuredClone(native);
    mutate(invalid.namespace_delete_payloads);
    assert.throws(() => checkNamespaceDeletePayloads(fixture, invalid, id, 'rust'));
  }
});

test('the embedded namespace delete payloads are explicitly inapplicable', () => {
  checkNamespaceDeletePayloads(fixture, {mode: 'embedded',
    namespace_delete_payloads: {applicable: false, reason: 'embedded_has_no_http_namespaces'}}, id);
  assert.throws(() => checkNamespaceDeletePayloads(fixture, {mode: 'embedded', namespace_delete_payloads: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['a delete refused for payloads it settles', state => { state.steps.deleted = structuredClone(state.steps['storage off: refused']); }],
  ['a delete without storage accepted', state => { state.steps['storage off: refused'] = structuredClone(state.steps.deleted); }],
  ['a refused delete that removed the namespace', state => { state.steps['storage off: namespace remains'].status = 404; }],
  ['an uncounted sole payload', state => { state.steps.deleted.payloads.external_payloads_deleted = 0; }],
  ['a peer-held payload counted as settled', state => { state.steps.deleted.payloads.external_payloads_deleted = 2; }],
  ['an unused upload left registered', state => { state.steps.deleted.payloads.runtime_external_payload_references = 1; }],
  ['a peer-held object deleted', state => { state.steps['peer fetched'] = {status: 404, reason: 'external_payload_not_found'}; }],
  ['an idle upload left registered', state => { state.steps['idle deleted'].payloads = {}; }],
  ['an answer naming another namespace', state => { state.steps['storage off: refused'].namespace_matches = false; }],
  ['a missing step', state => { delete state.steps['idle deleted']; }],
  ['a refused upload', state => { state.setup.uploads[0] = 422; }],
  ['a shared storage root', state => { state.storage_root = 'file:///state/objects'; }],
  ['another namespace', state => { state.namespace = 'default'; }],
]) {
  test(`the namespace delete payloads reject ${label}`, () => {
    const value = observation();
    mutate(value.namespace_delete_payloads);
    assert.throws(() => checkNamespaceDeletePayloads(fixture, value, id));
  });
}
