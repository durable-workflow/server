import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

// Published PHP's reviewed, normalized responses to every step.
const EXPECTED = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityExpected/schedule-policies.json', import.meta.url)));

// Generated identities, times, audit fingerprints, route names, peer
// addresses and signed continuation tokens differ between servers and runs.
// Next fire times are checked on their own, against the published cron
// corpus semantics.
const VOLATILE = [/^\d{4}-\d{2}-\d{2}T/, /^[0-9A-Za-z]{26}$/, /^sha256:[0-9a-f]{64}$/, /^generated::/,
  /^api\.schedules\./, /^\d+\.\d+\.\d+\.\d+$/, /^[A-Za-z0-9_-]{40,}\.[0-9a-f]{64}$/];
// How late a backfilled or scheduled occurrence fired depends on the clock.
const VOLATILE_FIELDS = new Set(['fire_lag_ms']);

// The run's own namespace and task queue read as placeholders, wherever they appear.
export function normalized(value, names = {}) {
  if (Array.isArray(value)) return value.map(item => normalized(item, names));
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([field, item]) =>
      [field, VOLATILE_FIELDS.has(field) ? '@v' : normalized(item, names)]));
  }
  if (typeof value !== 'string') return value;
  if (VOLATILE.some(pattern => pattern.test(value))) return '@v';
  return Object.entries(names).reduce((text, [name, placeholder]) => text.replaceAll(name, placeholder), value);
}

/// The reviewed status of each expanded step, in order.
const POLICY = [201, 200, 200, 200, 200];
export const STATUSES = [422, 422, 201, 200, 409, 422, 422, 201, 200, 200, 422, 200, 200, 422, 404,
  ...POLICY, ...POLICY, ...POLICY, ...POLICY, ...POLICY, ...POLICY,
  201, 200, 200, 404, 200, 200, 422, 200, 200, 200, 200, 200, 200, 404, 200];

/// The reviewed trigger outcomes of each overlap policy, three triggers each.
const POLICY_OUTCOMES = {
  skip: ['triggered', 'skipped', 'skipped'],
  buffer_one: ['triggered', 'buffered', 'buffer_full'],
  buffer_all: ['triggered', 'buffered', 'buffered'],
  allow_all: ['triggered', 'triggered', 'triggered'],
  cancel_other: ['triggered', 'triggered', 'triggered'],
  terminate_other: ['triggered', 'triggered', 'triggered'],
};

export function checkSchedulePolicies(fixture, observation, workflowId) {
  const state = observation.schedule_policies;
  assert.ok(state && typeof state === 'object', 'actual schedule policy observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_schedules'},
      'embedded schedule surface explicitly inapplicable');
    return {scope: 'schedule_policies'};
  }
  assert.ok(Number.isInteger(state.attempts) && state.attempts >= 1 && state.attempts <= 3, 'recorded sequence attempts');
  // A sequence replayed after PHP's SQLite lock defect runs in a new namespace.
  const namespace = `${workflowId}-schedules`.toLowerCase() + (state.attempts === 1 ? '' : `-r${state.attempts}`);
  assert.deepStrictEqual([state.applicable, state.namespace], [true, namespace], 'schedule policy namespace');
  assert.ok(Number.isInteger(state.retries) && state.retries >= 0, 'recorded lock retries');
  const raw = state.steps;
  const names = {[namespace]: '@namespace', [state.queue]: '@queue'};
  const steps = raw.map(step => ({request: step.request, status: step.status, response: normalized(step.response, names)}));
  assert.deepStrictEqual(steps.map(step => step.status), STATUSES, 'schedule step statuses');
  const at = request => steps.filter(step => step.request === request);
  // Laravel names expanded attributes raw and others with spaces.
  assert.equal(steps[1].response.message, 'The spec.timezone field must be a string. (and 9 more errors)', 'validation summary');
  assert.deepStrictEqual(steps[1].response.errors['spec.cron_expressions.0'],
    ['The spec.cron_expressions.0 field must be a string.'], 'expanded attribute name');
  assert.deepStrictEqual(steps[1].response.errors['action.workflow_type'],
    ['The action.workflow type field is required.'], 'plain attribute name');
  assert.equal(steps[4].response.reason, 'schedule_already_exists', 'duplicate schedule');
  assert.equal(steps[5].response.reason, 'invalid_cron_expression', 'invalid cron expression');
  assert.equal(steps[6].response.reason, 'invalid_schedule_spec', 'empty specification');
  const yearly = steps[3].response;
  assert.match(raw[3].response.next_fire_at, /^\d{4}-01-01T02:00:00\+00:00$/, 'yearly 03:00 Budapest fire time');
  assert.equal(yearly.note, 'yearly report', 'trimmed note');
  assert.deepStrictEqual(yearly.memo, {owner: 'λ'}, 'schedule memo');
  const mixed = at(`GET schedules/mixed`);
  assert.deepStrictEqual([mixed[0].response.status, mixed[1].response.status], ['paused', 'active'], 'paused creation resumed');
  assert.deepStrictEqual(mixed[1].response.note, 'back', 'resume note');
  assert.deepStrictEqual(mixed[1].response.action, {workflow_type: fixture.workflow_type, task_queue: '@queue',
    execution_timeout_seconds: 90, run_timeout_seconds: 30}, 'action timeout aliases');
  for (const [policy, outcomes] of Object.entries(POLICY_OUTCOMES)) {
    const id = `policy-${policy.replaceAll('_', '-')}`;
    assert.deepStrictEqual(at(`POST schedules/${id}/trigger`).slice(0, 3).map(step => step.response.outcome), outcomes,
      `${policy} trigger outcomes`);
  }
  const quota = at('POST schedules/quota/trigger');
  assert.deepStrictEqual(quota.map(step => step.response.outcome), ['triggered', 'triggered'], 'two quota runs');
  const skipped = at('POST schedules/policy-skip/trigger').at(-1).response;
  assert.deepStrictEqual([skipped.outcome, skipped.reason], ['skipped', 'status_not_triggerable'], 'paused trigger');
  assert.deepStrictEqual(steps, EXPECTED, 'every response matches the reviewed published PHP responses');
  return {scope: 'schedule_policies'};
}
