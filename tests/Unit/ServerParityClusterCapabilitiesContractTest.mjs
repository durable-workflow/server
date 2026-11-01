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

for (const [name, mutate] of [
  ['native copying a manifest flag it does not publish', state => { state.capabilities.timer_runtime_contract = true; }],
  ['native advertising response compression', state => { state.capabilities.response_compression = ['gzip']; }],
  ['native without its unqualified list', state => { state.native_unqualified = []; }],
  ['an activity result read that differs', state => { state.activity_result.available.response.available = false; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation('rust'); mutate(raw.cluster_capabilities);
  assert.throws(() => checkClusterCapabilities(fixture, raw));
});
