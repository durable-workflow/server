import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkNamespaceDelete} from '../../scripts/conformance/server-parity/namespace-delete-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/namespace-delete.json', import.meta.url)));
const namespace = 'test-delete';
const gone = {status: 404, response: {message: `Namespace [${namespace}] not found.`, reason: 'namespace_not_found', namespace}};
// What published PHP answers for this fixture: 2.5.14's deletion, with the
// post-commit payload deletion counts 2.11.8 adds (#911).
const model = () => structuredClone({mode: 'http', namespace_delete: {applicable: true, namespace,
  created: {name: namespace, description: 'delete me λ', retention_mode: 'bounded', retention_days: 30, status: 'active'},
  search_attribute: 201, started: 'pending',
  deleted: {status: 200, response: {name: namespace, status: 'deleted',
    external_payload_cleanup: {pending: 0, failed: 0, blocked: 0}, deleted: {workflow_commands: 1,
    workflow_history_events: 2, workflow_tasks: 1, search_attribute_definitions: 1, workflow_run_summaries: 1,
    workflow_runs: 1, workflow_instances: 1}}},
  described_after: gone, deleted_again: gone,
  sdk_delete_again: {class: 'DurableWorkflow\\Exception\\ServerException', status: 404, reason: 'namespace_not_found'},
  own_run: 'TEST', restarted: 'TEST-doomed'}});
const projection = {scope: 'namespace_delete', deleted: fixture.namespace_delete.deleted};

test('published PHP deletion passes', () => assert.deepStrictEqual(checkNamespaceDelete(fixture, model(), 'TEST'), projection));
test("PHP's asynchronous projections may be counted", () => {
  const observation = model();
  Object.assign(observation.namespace_delete.deleted.response.deleted, {workflow_run_timeline_entries: 2,
    workflow_run_lineage_entries: 1});
  checkNamespaceDelete(fixture, observation, 'TEST');
});
test('embedded explicitly declares HTTP namespaces inapplicable', () => assert.deepStrictEqual(checkNamespaceDelete(fixture,
  {mode: 'embedded', namespace_delete: {applicable: false, reason: 'embedded_has_no_http_namespaces'}}, 'test'), projection));
for (const [label, mutate] of [
  ['a missing observation', s => { for (const key of Object.keys(s)) delete s[key]; }],
  ['another namespace', s => s.namespace = 'default'],
  ['a refused delete', s => s.deleted = {status: 503, response: {reason: 'external_payload_storage_driver_unavailable'}}],
  ['another status word', s => s.deleted.response.status = 'removed'],
  ['the cleanup counts missing', s => delete s.deleted.response.external_payload_cleanup],
  ['a pending provider deletion', s => s.deleted.response.external_payload_cleanup.pending = 1],
  ['an owned table left behind', s => delete s.deleted.response.deleted.workflow_tasks],
  ['a count off by one', s => s.deleted.response.deleted.workflow_history_events = 3],
  ['a zero count reported', s => s.deleted.response.deleted.workflow_memos = 0],
  ['an extra table deleted', s => s.deleted.response.deleted.workflow_schedules = 1],
  ['a zero asynchronous projection reported', s => s.deleted.response.deleted.workflow_run_timeline_entries = 0],
  ['a deleted namespace still described', s => s.described_after = {status: 200, response: s.created}],
  ['a second delete accepted', s => s.deleted_again = s.deleted],
  ['another not-found message', s => s.deleted_again.response.message = 'Namespace not found.'],
  ['the SDK accepting a second delete', s => s.sdk_delete_again = {accepted: true}],
  ["another namespace's run removed", s => s.own_run = null],
  ['the workflow ID still reserved', s => s.restarted = null],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation.namespace_delete);
    assert.throws(() => checkNamespaceDelete(fixture, observation, 'TEST'));
  });
}
