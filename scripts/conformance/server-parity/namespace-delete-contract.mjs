import assert from 'node:assert/strict';

// Reviewed namespace deletion (NamespaceController::destroy over
// NamespaceLifecycleCleanup). The namespace holds one search attribute
// definition and one started, unclaimed run. The deleted counts are exact,
// except PHP's timeline, timer and lineage projections, which PHP fills
// asynchronously: they may be absent or positive. Embedded applications have
// no HTTP namespaces, so the projection is the definition alone.
export function checkNamespaceDelete(fixture, observation, workflowId) {
  const definition = fixture.namespace_delete;
  const state = observation.namespace_delete;
  const projection = {scope: 'namespace_delete', deleted: definition.deleted};
  assert.ok(state && typeof state === 'object', 'actual namespace delete observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_namespaces'},
      'embedded namespace delete explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const namespace = `${workflowId}-delete`.toLowerCase();
  equal([state.applicable, state.namespace, state.created?.name, state.created?.status], [true, namespace, namespace, 'active'],
    'namespace created');
  equal([state.search_attribute, state.started], [201, 'pending'], 'definition and run in the namespace');

  equal(state.deleted.status, 200, 'namespace deleted');
  const {deleted, ...response} = state.deleted.response;
  // 2.11.8 always reports its post-commit payload deletions (#911).
  equal(response, {name: namespace, status: 'deleted', external_payload_cleanup: definition.external_payload_cleanup},
    'delete response');
  const exact = Object.fromEntries(Object.entries(deleted).filter(([key]) => !definition.asynchronous_projections.includes(key)));
  equal(exact, definition.deleted, 'each owned table deleted with its count');
  for (const key of definition.asynchronous_projections) {
    assert.ok(!(key in deleted) || (Number.isInteger(deleted[key]) && deleted[key] > 0), `${key} absent or positive`);
  }

  const gone = {status: 404, response: {message: `Namespace [${namespace}] not found.`, reason: 'namespace_not_found', namespace}};
  equal(state.described_after, gone, 'a deleted namespace reads as not found');
  equal(state.deleted_again, gone, 'a deleted namespace cannot be deleted again');
  equal(state.sdk_delete_again, {class: 'DurableWorkflow\\Exception\\ServerException', status: 404, reason: 'namespace_not_found'},
    'the published SDK refuses a second delete');
  equal(state.own_run, workflowId, 'a run in another namespace remains');
  equal(state.restarted, `${workflowId}-doomed`, "the deleted run's workflow ID starts a new run");
  return projection;
}
