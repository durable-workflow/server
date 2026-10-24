import assert from 'node:assert/strict';

// Synchronous update validation, as published Server 2.11.1 serves it. Every
// recorded step must equal the reviewed fixture after three normalizations:
// - identifiers become labels in the order they first appear, walking object
//   keys in sorted order so either server's key order reads alike; a reused
//   identifier (an update keeping its validation task's id, a retry returning
//   its receipt) must be reused, and a new one must be new;
// - timestamps become "<time>";
// - the probe's workflow id prefix becomes "<workflow>".
// The requester (`principal`) and the control-plane contract block are left
// to the fixtures that own them. Each timeout must actually have waited for
// the validation timeout.
const ULID = /^[0-9A-Za-z]{26}$/;
const TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/;
const UNCOMPARED = new Set(['principal', 'control_plane', 'seconds']);
const TIMEOUTS = ['unclaimed', 'worker_lost', 'execution_timeout'];
const VALIDATION_SECONDS = [9, 20];

export function normalizeUpdateValidation(state, workflowId) {
  const prefix = `${workflowId}-uv`;
  const labels = new Map();
  const walk = value => {
    if (Array.isArray(value)) return value.map(walk);
    if (value !== null && typeof value === 'object') {
      return Object.fromEntries(Object.entries(value).filter(([key]) => !UNCOMPARED.has(key))
        .sort(([left], [right]) => (left < right ? -1 : left > right ? 1 : 0))
        .map(([key, item]) => [key, walk(item)]));
    }
    if (typeof value !== 'string') return value;
    if (value.startsWith(prefix)) return `<workflow>${value.slice(prefix.length)}`;
    if (TIME.test(value)) return '<time>';
    if (ULID.test(value)) {
      if (!labels.has(value)) labels.set(value, `<id:${labels.size + 1}>`);
      return labels.get(value);
    }
    return value;
  };
  const {applicable, workflow_id: _workflow, ...steps} = state;
  return walk(steps);
}

export function checkUpdateValidation(fixture, observation, workflowId) {
  const state = observation.update_validation;
  assert.ok(state && typeof state === 'object', 'actual update validation observation');
  const expected = fixture.update_validation.expected;
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_update_validation'},
      'embedded update validation explicitly inapplicable');
    return {scope: 'update_validation', steps: Object.keys(expected).length};
  }
  assert.deepStrictEqual([state.applicable, state.workflow_id], [true, `${workflowId}-uv`], 'update validation workflow');
  for (const step of TIMEOUTS) {
    const seconds = state[step]?.seconds;
    assert.ok(typeof seconds === 'number' && seconds >= VALIDATION_SECONDS[0] && seconds <= VALIDATION_SECONDS[1],
      `update validation ${step} waited for the validation timeout (${seconds} seconds)`);
  }
  const actual = normalizeUpdateValidation(state, workflowId);
  assert.deepStrictEqual(Object.keys(actual), Object.keys(expected), 'update validation steps');
  for (const step of Object.keys(expected)) {
    assert.deepStrictEqual(actual[step], expected[step], `update validation ${step}`);
  }
  return {scope: 'update_validation', steps: Object.keys(expected).length};
}
