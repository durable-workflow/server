import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkClusterCapabilities, expectedFor} from '../../scripts/conformance/server-parity/cluster-capabilities-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/cluster-capabilities.json', import.meta.url), 'utf8'));
const definition = fixture.cluster_capabilities;
const observation = implementation => ({mode: 'http', cluster_capabilities: {implementation, ...expectedFor(definition, implementation)}});

test('published PHP and native each pass with their reviewed answers', () => {
  assert.deepStrictEqual(checkClusterCapabilities(fixture, observation(null)), {scope: 'cluster_capabilities'});
  assert.deepStrictEqual(checkClusterCapabilities(fixture, observation('rust')), {scope: 'cluster_capabilities'});
});

test('embedded applications have no HTTP cluster info', () => {
  assert.deepStrictEqual(checkClusterCapabilities(fixture,
    {mode: 'embedded', cluster_capabilities: {applicable: false, reason: 'embedded_has_no_http_cluster_info'}}),
  {scope: 'cluster_capabilities'});
});

test('the activity result read is pending, then available, and unknown activities are 404', () => {
  const read = definition.expected.activity_result;
  assert.deepStrictEqual([read.pending.response.state, read.available.response.state, read.unknown.status],
    ['pending', 'available', 404]);
  assert.equal(definition.expected.capabilities.workflow_activity_result_inspection, true);
  assert.ok(!Object.keys(definition.native_differences).includes('capabilities.workflow_activity_result_inspection'),
    'native advertises the read too');
});

test('every reviewed native difference names a published member and gives a reason', () => {
  for (const [path, difference] of Object.entries(definition.native_differences)) {
    assert.ok(typeof difference.reason === 'string' && difference.reason.length > 10, path);
  }
  assert.doesNotThrow(() => expectedFor(definition, 'rust'));
});

test('every reviewed native addition is a member published PHP lacks and gives a reason', () => {
  for (const [path, addition] of Object.entries(definition.native_additions)) {
    const member = path.split('.').pop();
    assert.ok(!Object.hasOwn(definition.expected.capabilities, member), path);
    assert.ok(typeof addition.reason === 'string' && addition.reason.length > 10, path);
  }
  assert.equal(expectedFor(definition, 'rust').capabilities.child_workflow_start_turn, true);
  assert.ok(!Object.hasOwn(expectedFor(definition, null).capabilities, 'child_workflow_start_turn'), 'published PHP never had it');
  const shadowing = structuredClone(definition);
  shadowing.native_additions = {'capabilities.child_workflows': {value: false, reason: 'not an addition at all'}};
  assert.throws(() => expectedFor(shadowing, 'rust'), /is not a published member/);
});

for (const [name, mutate] of [
  ['native without its child start turn', state => { delete state.capabilities.child_workflow_start_turn; }],
  ['native copying a manifest flag it does not publish', state => { state.capabilities.timer_runtime_contract = true; }],
  ['native advertising response compression', state => { state.capabilities.response_compression = ['gzip']; }],
  ['native without its unqualified list', state => { state.native_unqualified = []; }],
  ['an activity result read that differs', state => { state.activity_result.available.response.available = false; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation('rust'); mutate(raw.cluster_capabilities);
  assert.throws(() => checkClusterCapabilities(fixture, raw));
});

// server#1005: native serves client_compatibility and supported_sdk_versions
// from one source, citing only protocols it serves.
test('both implementations state each client range the same way in both members', () => {
  for (const implementation of [null, 'rust']) {
    for (const [client, columns] of Object.entries(expectedFor(definition, implementation).client_ranges)) {
      assert.equal(columns.client_compatibility, columns.supported_sdk_versions, `${implementation ?? 'php'} ${client}`);
    }
  }
  assert.equal(expectedFor(definition, null).client_ranges.rust.supported_sdk_versions, '>=2.0.0,<4.0.0', 'published PHP');
  assert.equal(expectedFor(definition, 'rust').client_ranges.rust.supported_sdk_versions, '>=4.10.3,<5.0.0', 'native');
});

test('every native range bound names its qualification source', () => {
  const reason = definition.native_differences.client_ranges.reason;
  for (const source of ['sdk-php/pull/243', 'server#985', 'sdk-python/issues/228', 'sdk-rust/pull/160', 'cli#72']) {
    assert.ok(reason.includes(source), source);
  }
});

const phpLikeBlock = state => {
  const block = state.native_client_compatibility;
  block.required_protocols.worker_protocol.external_task_input_contract = {schema: 'durable-workflow.v2.external-task-input.contract', version: 1};
  block.clients.cli.requires.push('worker_protocol.external_task_input_contract');
};
for (const [name, mutate] of [
  ['native 3.0.5 without a client compatibility block', state => {
    state.native_client_compatibility = 'absent';
    for (const columns of Object.values(state.client_ranges)) {
      columns.supported_sdk_versions = null; columns.client_compatibility = null;
    }
  }],
  ['native citing an external execution contract it does not publish, as published PHP does', phpLikeBlock],
  ['native citing the skew refusal matrix', state => {
    state.native_client_compatibility.required_protocols.skew_refusal_matrix = {schema: 'x', version: 1};
  }],
  ['a client requirement the block does not cite', state => {
    state.native_client_compatibility.clients['sdk-rust'].requires.push('control_plane.unserved');
  }],
  ['a client range that differs between the two members', state => {
    state.client_ranges.php.client_compatibility = '>=3.0.0,<4.0.0';
  }],
  ['a native range that differs from the reviewed one in both members', state => {
    state.client_ranges.rust = {supported_sdk_versions: '>=4.10.2,<5.0.0', client_compatibility: '>=4.10.2,<5.0.0'};
  }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation('rust'); mutate(raw.cluster_capabilities);
  assert.throws(() => checkClusterCapabilities(fixture, raw));
});

test('published PHP ranges that disagree between the two members are rejected', () => {
  const raw = observation(null);
  raw.cluster_capabilities.client_ranges.cli.client_compatibility = '>=1.0.0,<2.0.0';
  assert.throws(() => checkClusterCapabilities(fixture, raw));
});

test('an unknown implementation is refused', () => {
  const raw = observation('rust');
  raw.cluster_capabilities.implementation = 'native';
  assert.throws(() => checkClusterCapabilities(fixture, raw), /published PHP or native/);
});
