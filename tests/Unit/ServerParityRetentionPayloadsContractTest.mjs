import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkRetentionPayloads} from '../../scripts/conformance/server-parity/retention-payloads-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/retention-external-payloads.json', import.meta.url)));
const definition = fixture.retention_payloads;
const id = 'parity-v1-retention-external-payloads';
const namespace = `${id}-retention`;

const observation = () => ({mode: 'http', retention_payloads: {applicable: true, namespace, peer: `${namespace}-peer`,
  storage_root: `file:///state/retention-payloads-parity-server/${namespace}`,
  setup: structuredClone(definition.setup), steps: structuredClone(definition.steps)}});

test('the retention payloads accept the published answers', () => {
  assert.deepStrictEqual(checkRetentionPayloads(fixture, observation(), id),
    {scope: 'retention_payloads', steps: Object.keys(definition.steps)});
});

test('the fixture deletes, keeps and skips', () => {
  const results = Object.values(definition.steps).filter(step => step.result).map(step => step.result);
  assert.deepStrictEqual(results.map(result => result.external_payloads_deleted ?? result.reason),
    [1, 0, 1, 0, 1, 0, 'external_payload_storage_driver_unavailable', 1]);
  assert.deepStrictEqual(Object.values(definition.steps).filter(step => !step.result).map(step => step.status),
    [404, 200, 404, 200, 404]);
});

test('the embedded retention payloads are explicitly inapplicable', () => {
  checkRetentionPayloads(fixture, {mode: 'embedded', retention_payloads: {applicable: false, reason: 'embedded_has_no_http_system_routes'}}, id);
  assert.throws(() => checkRetentionPayloads(fixture, {mode: 'embedded', retention_payloads: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['a skipped run that holds a payload', state => { state.steps['sole pruned'].result = {outcome: 'skipped', reason: 'external_payload_cleanup_unavailable'}; }],
  ['an uncounted deletion', state => { state.steps['sole pruned'].result.external_payloads_deleted = 0; }],
  ['a payload left after its run', state => { state.steps['sole fetched'] = {status: 200, bytes_match: true}; }],
  ['a held payload deleted', state => { state.steps['shared fetched while held'] = {status: 404, reason: 'external_payload_not_found'}; }],
  ['a peer-held payload counted', state => { state.steps['peer-held pruned'].result.external_payloads_deleted = 1; }],
  ['a peer-held object deleted', state => { state.steps['peer-held fetched'] = {status: 404, reason: 'external_payload_not_found'}; }],
  ['a prune without storage', state => { state.steps['storage off: sole skipped'].result = {outcome: 'pruned', external_payloads_deleted: 0}; }],
  ['another run pruned', state => { state.steps['shared last pruned'].run = 'shared-1'; }],
  ['a missing step', state => { delete state.steps['storage on: sole fetched']; }],
  ['a refused upload', state => { state.setup.uploads[0] = 422; }],
  ['a shared storage root', state => { state.storage_root = 'file:///state/objects'; }],
  ['another namespace', state => { state.namespace = 'default'; }],
]) {
  test(`the retention payloads reject ${label}`, () => {
    const value = observation();
    mutate(value.retention_payloads);
    assert.throws(() => checkRetentionPayloads(fixture, value, id));
  });
}
