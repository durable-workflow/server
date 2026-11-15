import assert from 'node:assert/strict';
import {nativeDivergence} from './native-divergences.mjs';

// Reviewed external payloads of pruned runs (HistoryRetentionEnforcer::pruneRun
// with ExternalPayloadRetentionCleanup::deleteForRun). Every setup answer and
// step is exact; each pass names its own run. Embedded applications have no
// HTTP system routes.
export function checkRetentionPayloads(fixture, observation, workflowId, target) {
  const definition = fixture.retention_payloads;
  const state = observation.retention_payloads;
  const projection = {scope: 'retention_payloads', steps: Object.keys(definition.steps)};
  assert.ok(state && typeof state === 'object', 'actual retention payloads observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_system_routes'},
      'embedded retention payloads explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-retention`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace, state.peer], [true, namespace, `${namespace}-peer`],
    'retention namespaces');
  // A storage root of this target's own, under the namespace.
  const root = state.storage_root;
  assert.ok(typeof root === 'string' && /^file:\/\/\/state\/retention-payloads-[a-z0-9-]+\//.test(root)
    && root.endsWith(`/${namespace}`) && root.split('/').length === 6, 'a storage root of its own');
  assert.deepStrictEqual(state.setup, definition.setup, 'every upload, run and policy accepted');
  assert.deepStrictEqual(Object.keys(state.steps), Object.keys(definition.steps), 'every step recorded in order');
  // Disabling new writes no longer removes native's original storage binding.
  // Keep published PHP's refusal exact, and require native's successful delete
  // and subsequent run-not-found readback instead of accepting either result.
  const steps = {...definition.steps};
  if (nativeDivergence(target, definition.native_divergence)) {
    assert.deepStrictEqual(Object.keys(definition.native_expected),
      ['storage off: sole skipped', 'storage on: sole pruned'], 'only the reviewed policy-change steps differ');
    Object.assign(steps, definition.native_expected);
  }
  for (const [step, expected] of Object.entries(steps)) {
    assert.deepStrictEqual(state.steps[step], expected, step);
  }
  return projection;
}
