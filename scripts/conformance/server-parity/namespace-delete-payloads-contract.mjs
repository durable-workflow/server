import assert from 'node:assert/strict';
import {nativeDivergence} from './native-divergences.mjs';

// Reviewed external payloads of a deleted namespace (NamespaceController::destroy
// over ExternalPayloadRetentionCleanup::deleteForNamespaceCleanup, then
// RuntimeExternalPayloadRegistry::deleteForNamespace). Every setup answer and
// step is exact; namespace names are per run, so each delete records whether
// its answer names its own namespace. Embedded applications have no HTTP
// namespaces.
export function checkNamespaceDeletePayloads(fixture, observation, workflowId, target) {
  const definition = fixture.namespace_delete_payloads;
  const state = observation.namespace_delete_payloads;
  const projection = {scope: 'namespace_delete_payloads', steps: Object.keys(definition.steps)};
  assert.ok(state && typeof state === 'object', 'actual namespace delete payloads observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_namespaces'},
      'embedded namespace delete payloads explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-nsdelete`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace, state.peer, state.idle],
    [true, namespace, `${namespace}-peer`, `${namespace}-idle`], 'namespace delete namespaces');
  // A storage root of this target's own, under the namespace.
  const root = state.storage_root;
  assert.ok(typeof root === 'string' && /^file:\/\/\/state\/namespace-delete-payloads-[a-z0-9-]+\//.test(root)
    && root.endsWith(`/${namespace}`) && root.split('/').length === 6, 'a storage root of its own');
  const native = nativeDivergence(target, definition.native_divergence);
  const expected = native ? JSON.parse(JSON.stringify(definition.native_expected).replaceAll('{namespace}', namespace)) : {};
  // Native can finish original-object cleanup while new writes are disabled.
  // The first delete removes the namespace, so enabling or deleting it again
  // must return the exact missing-namespace result, not repeat cleanup.
  assert.deepStrictEqual(state.setup, {...definition.setup, ...expected.setup}, 'every upload, run and policy accepted');
  assert.deepStrictEqual(Object.keys(state.steps), Object.keys(definition.steps), 'every step recorded in order');
  if (native) {
    assert.deepStrictEqual(Object.keys(expected.setup), ['storage_on'], 'only the deleted namespace policy changes');
    assert.deepStrictEqual(Object.keys(expected.steps),
      ['storage off: refused', 'storage off: namespace remains', 'deleted'], 'only original deletion and its repeat differ');
  }
  for (const [step, value] of Object.entries({...definition.steps, ...expected.steps})) {
    assert.deepStrictEqual(state.steps[step], value, step);
  }
  return projection;
}
