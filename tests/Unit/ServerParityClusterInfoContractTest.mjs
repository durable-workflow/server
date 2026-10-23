import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkClusterInfo, expectedTransport} from '../../scripts/conformance/server-parity/cluster-info-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/cluster-info.json', import.meta.url)));
const definition = fixture.cluster_info;
const id = 'parity-v1-cluster-info';
const names = Object.fromEntries(Object.entries(definition.namespaces).map(([key, suffix]) => [key, `${id}-${suffix}`]));

// The answers a server gives when it matches the fixture. `implementation` is
// null for published PHP and 'rust' for native.
const observation = (implementation = null) => {
  const transport = expectedTransport(definition, implementation);
  const policies = Object.fromEntries(Object.entries(definition.policies).map(([key, policy]) =>
    [key, {status: 200, response: {name: names[key], external_payload_storage: structuredClone(policy)}}]));
  const discovery = Object.fromEntries(Object.keys(definition.namespaces).map(key => [key, {
    status: 200, implementation, limits: structuredClone(definition.limits),
    default_namespace: 'default', server_id_is_text: true, transport_capability: structuredClone(transport),
    namespace: {name: names[key], ...definition.rows[key],
      external_payload_storage: {...structuredClone(definition.storage_envelope), ...definition.storage[key], transport: structuredClone(transport)}},
  }]));
  return {mode: 'http', cluster_info: {applicable: true, namespaces: names, policies, discovery}};
};
const statuses = {available: 'available', unconfigured: 'unconfigured', disabled: 'disabled', missing: 'unconfigured'};

test('cluster info accepts the published PHP answers', () => {
  assert.deepStrictEqual(checkClusterInfo(fixture, observation(), id), {scope: 'cluster_info', cases: 4, statuses});
});

test('cluster info accepts native answers with the reviewed differences', () => {
  assert.deepStrictEqual(checkClusterInfo(fixture, observation('rust'), id), {scope: 'cluster_info', cases: 4, statuses});
});

test('the reviewed native differences are each a published member', () => {
  const php = expectedTransport(definition, null);
  const native = expectedTransport(definition, 'rust');
  assert.equal(php.upload.completion_context.prepared_schema, 'durable-workflow.v2.payload-completion-context.v2');
  assert.equal(native.upload.completion_context.prepared_schema, null);
  assert.equal(native.upload.completion_context.scope_schema, null);
  assert.deepStrictEqual(native.audit_events, []);
  assert.deepStrictEqual(php.audit_events.length, 4);
  for (const {reason} of Object.values(definition.native_differences)) assert.ok(reason.length > 20, 'each difference has a reason');
  const unchanged = structuredClone(native);
  unchanged.upload.completion_context.prepared_schema = php.upload.completion_context.prepared_schema;
  unchanged.upload.completion_context.scope_schema = php.upload.completion_context.scope_schema;
  unchanged.audit_events = php.audit_events;
  assert.deepStrictEqual(unchanged, php, 'native differs only in the reviewed members');
});

test('the embedded cluster info is explicitly inapplicable', () => {
  checkClusterInfo(fixture, {mode: 'embedded', cluster_info: {applicable: false, reason: 'embedded_has_no_http_cluster_info'}}, id);
  assert.throws(() => checkClusterInfo(fixture, {mode: 'embedded', cluster_info: {applicable: true}}, id));
});

const read = (state, key) => state.discovery[key];
for (const [label, implementation, mutate] of [
  ['a server without discovery', 'rust', state => { for (const key of Object.keys(state.discovery)) read(state, key).namespace = null; }],
  ['a missing transport capability', 'rust', state => { read(state, 'available').transport_capability = null; }],
  ['native claiming the v2 prepared context', 'rust', state => {
    for (const key of Object.keys(state.discovery)) read(state, key).transport_capability.upload.completion_context.prepared_schema = 'durable-workflow.v2.payload-completion-context.v2';
  }],
  ['native claiming audit events', 'rust', state => {
    for (const key of Object.keys(state.discovery)) read(state, key).transport_capability.audit_events = ['external_payload.uploaded'];
  }],
  ['PHP dropping its audit events', null, state => {
    for (const key of Object.keys(state.discovery)) read(state, key).transport_capability.audit_events = [];
  }],
  ['a disabled policy reported available', null, state => { read(state, 'disabled').namespace.external_payload_storage.status = 'available'; }],
  ['a disabled policy losing its threshold', 'rust', state => { read(state, 'disabled').namespace.external_payload_storage.threshold_bytes = 2097152; }],
  ['an unconfigured namespace without the request limit', 'rust', state => { read(state, 'unconfigured').namespace.external_payload_storage.threshold_bytes = 16; }],
  ['a missing namespace reported as existing', 'rust', state => { read(state, 'missing').namespace.exists = true; }],
  ['another namespace read', 'rust', state => { read(state, 'available').namespace.name = 'default'; }],
  ['a namespace transport unlike the capability', 'rust', state => { read(state, 'available').namespace.external_payload_storage.transport.limits.max_payload_bytes = 1; }],
  ['another request limit', null, state => { read(state, 'missing').limits.max_payload_bytes = 1048576; }],
  ['an exposed provider', 'rust', state => { read(state, 'available').namespace.external_payload_storage.provider_details_exposed = true; }],
  ['a lost policy', 'rust', state => { state.policies.disabled.status = 422; }],
  ['mixed servers', 'rust', state => { read(state, 'missing').implementation = null; }],
  ['a missing server identity', null, state => { read(state, 'available').server_id_is_text = false; }],
]) {
  test(`cluster info rejects ${label}`, () => {
    const value = observation(implementation);
    mutate(value.cluster_info);
    assert.throws(() => checkClusterInfo(fixture, value, id));
  });
}
