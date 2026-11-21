import assert from 'node:assert/strict';
import {nativeDivergence} from './native-divergences.mjs';
import {withoutNativeRedriveActions} from './redrive-actions.mjs';
import {withoutEmptyTaskAttention} from './task-attention.mjs';

// Native queries closed runs and refuses a run without retained history with
// query_history_unavailable (#587), which its query contract lists right
// after run_not_active. Each native query contract must list it there once;
// it is then projected away so the rest compares with the published recording.
export function withoutNativeQueryHistoryReason(state, target) {
  if (!nativeDivergence(target, 'closed-run-queries')) return state;
  for (const [step, operations] of Object.entries(state)) {
    const reasons = operations?.query?.response?.control_plane?.contract?.rejection_reasons;
    if (!Array.isArray(reasons)) continue;
    const at = reasons.indexOf('query_history_unavailable');
    assert.ok(at > 0 && reasons[at - 1] === 'run_not_active' && reasons.lastIndexOf('query_history_unavailable') === at,
      `waterline import ${step}: native query contract lists query_history_unavailable once after run_not_active`);
    reasons.splice(at, 1);
  }
  return state;
}

// Waterline v1 projection import (LegacyV1ProjectionController), recorded
// against published Server 2.11.1. The fixture's `expected` holds the whole
// normalized observation, with the probe's workflow prefix as `{id}`,
// projected identities, event ids, dedupe keys and export checksums as
// `{<label>.<token>}` labels, and import and export times as placeholders.
// Every step compares exactly.
export function checkWaterlineImport(fixture, observation, workflowId, target) {
  const state = observation.waterline_import;
  const projection = {scope: 'waterline_import'};
  assert.ok(state && typeof state === 'object', 'actual waterline import observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_waterline_import'},
      'embedded waterline import explicitly inapplicable');
    return projection;
  }
  const definition = fixture.waterline_import;
  const normalized = withoutEmptyTaskAttention(JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}')));
  const actual = withoutNativeQueryHistoryReason(
    withoutNativeRedriveActions(normalized, target, 'waterline import'), target);
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(actual[step], expected, `waterline import ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'waterline import steps');
  return projection;
}
