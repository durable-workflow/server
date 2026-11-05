import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkScheduleCatalog} from '../../scripts/conformance/server-parity/schedule-catalog-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/schedule-catalog.json', import.meta.url)));
const expected = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityExpected/schedule-catalog.json', import.meta.url)));
const workflowId = 'TEST-schedule-catalog';
const namespace = 'test-schedule-catalog-catalog';
const queue = 'test-schedule-catalog-queue';
// PHP's raw backfill fire times, which normalization hides.
const FIRE_TIMES = {57: ['2026-03-27T03:00:00+01:00', '2026-03-27T06:00:00+00:00', '2026-03-28T03:00:00+01:00',
  '2026-03-28T06:00:00+00:00', '2026-03-29T03:00:00+02:00', '2026-03-29T06:00:00+00:00', '2026-03-30T03:00:00+02:00'],
58: ['2026-03-27T03:00:00+01:00', '2026-03-27T06:00:00+00:00', '2026-03-28T03:00:00+01:00', '2026-03-28T06:00:00+00:00',
  '2026-03-29T03:00:00+02:00', '2026-03-29T06:00:00+00:00', '2026-03-30T03:00:00+02:00', '2026-03-30T06:00:00+00:00'],
61: ['2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00', '2026-01-03T00:00:00+00:00'],
62: ['2026-02-01T00:00:00+00:00', '2026-02-02T00:00:00+00:00'],
64: ['2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00', '2026-01-03T00:00:00+00:00'],
66: ['2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00'],
68: ['2026-01-01T03:00:00+01:00', '2026-01-02T03:00:00+01:00']};
const TOKEN = `${'eyJ2ZXJzaW9uIjoxLCJuYW1lc3BhY2UiOiJkZWZhdWx0In0'}.${'ab'.repeat(32)}`;
function model() {
  const steps = JSON.parse(JSON.stringify(expected).replaceAll('@namespace', namespace).replaceAll('@queue', queue));
  // Generated values as a server reports them before normalization.
  for (const [index, times] of Object.entries(FIRE_TIMES)) {
    steps[index].response.results.forEach((result, position) => { result.fire_time = times[position]; });
  }
  steps[9].response.next_page_token = TOKEN;
  steps[39].response.next_page_token = TOKEN;
  steps[10].response.last_safe_cursor.created_at = '2026-10-21T05:00:00.123456Z';
  return {mode: 'http', schedule_catalog: {applicable: true, namespace, queue, retries: 0, attempts: 1, steps}};
}
const projection = {scope: 'schedule_catalog'};

function nativeModel() {
  const observation = model();
  const steps = observation.schedule_catalog.steps;
  let sequence = 1;
  for (const step of steps) {
    for (const result of step.response.results ?? []) {
      if (result.outcome === 'started') result.workflow_id = String(sequence++).padStart(26, '0');
    }
    for (const item of step.response.schedules ?? []) Object.assign(item, {
      failures_count: 0, skipped_trigger_count: 0, latest_workflow_instance_id: null, current_run: null,
    });
  }
  const latest = {'bf': steps[58].response.results.at(-1).workflow_id,
    'bf-skip': steps[62].response.results.at(-1).workflow_id,
    'bf-buffer': steps[64].response.results.at(-1).workflow_id};
  for (const item of steps[71].response.schedules) {
    if (item.schedule_id === 'beta') item.skipped_trigger_count = 2;
    if (!Object.hasOwn(latest, item.schedule_id)) continue;
    item.latest_workflow_instance_id = latest[item.schedule_id];
    item.skipped_trigger_count = {'bf': 7, 'bf-skip': 2, 'bf-buffer': 0}[item.schedule_id];
    item.current_run = {run_id: String(sequence++).padStart(26, '0'), status: 'pending', status_bucket: 'running', is_terminal: false};
  }
  const skipped = steps[71].response.schedules.find(item => item.schedule_id === 'bf-skip');
  const audit = steps[70].response.events.filter(event => event.event_type === 'ScheduleTriggered').at(-1);
  audit.workflow_instance_id = skipped.latest_workflow_instance_id;
  audit.workflow_run_id = skipped.current_run.run_id;
  return observation;
}

test('native summaries are checked before the unchanged published PHP comparison', () => {
  const observation = nativeModel();
  const before = structuredClone(observation);
  assert.deepStrictEqual(checkScheduleCatalog(fixture, observation, workflowId, 'rust'), projection);
  assert.deepStrictEqual(observation, before, 'raw summary evidence is retained unchanged');
});
test('native target cannot omit all new summary fields', () => {
  assert.throws(() => checkScheduleCatalog(fixture, model(), workflowId, 'rust'), /complete schedule list summary/);
});
for (const [label, mutate] of [
  ['partial summary', o => { delete o.schedule_catalog.steps[4].response.schedules[0].failures_count; }],
  ['wrong failure count', o => { o.schedule_catalog.steps[4].response.schedules[0].failures_count = 1; }],
  ['wrong skip count', o => { o.schedule_catalog.steps[71].response.schedules[1].skipped_trigger_count = 0; }],
  ['wrong latest workflow', o => { o.schedule_catalog.steps[71].response.schedules[0].latest_workflow_instance_id = '9'.repeat(26); }],
  ['missing current run', o => { o.schedule_catalog.steps[71].response.schedules[0].current_run = null; }],
  ['shortened run identity', o => { o.schedule_catalog.steps[71].response.schedules[0].current_run.run_id = 'short'; }],
  ['invented terminal state', o => { o.schedule_catalog.steps[71].response.schedules[0].current_run.is_terminal = true; }],
  ['wrong audit run', o => { o.schedule_catalog.steps[70].response.events.filter(e => e.event_type === 'ScheduleTriggered').at(-1).workflow_run_id = '9'.repeat(26); }],
  ['unreviewed extra field', o => { o.schedule_catalog.steps[4].response.schedules[0].other_summary = 1; }],
  ['changed existing field', o => { o.schedule_catalog.steps[4].response.schedules[0].note = 'changed'; }],
]) {
  test(`rejects native ${label}`, () => {
    const observation = nativeModel();
    mutate(observation);
    assert.throws(() => checkScheduleCatalog(fixture, observation, workflowId, 'rust'));
  });
}

test('published PHP responses pass', () => assert.deepStrictEqual(checkScheduleCatalog(fixture, model(), workflowId), projection));
test('a sequence replayed in a new namespace passes', () => {
  const observation = model();
  const replayed = JSON.parse(JSON.stringify(observation).replaceAll(namespace, `${namespace}-r2`));
  replayed.schedule_catalog.attempts = 2;
  assert.deepStrictEqual(checkScheduleCatalog(fixture, replayed, workflowId), projection);
});
test('embedded explicitly declares the HTTP surface inapplicable', () => assert.deepStrictEqual(checkScheduleCatalog(fixture,
  {mode: 'embedded', schedule_catalog: {applicable: false, reason: 'embedded_has_no_http_schedules'}}, workflowId), projection));
for (const [label, mutate] of [
  ['another namespace', o => { o.schedule_catalog.namespace = 'default'; }],
  ['unrecorded attempts', o => { delete o.schedule_catalog.attempts; }],
  ['a missing step', o => { o.schedule_catalog.steps.pop(); }],
  ['oldest schedules first', o => { o.schedule_catalog.steps[4].response.schedules.reverse(); }],
  ['a last page with a token', o => { o.schedule_catalog.steps[11].response.next_page_token = TOKEN; }],
  ['a token reused with other filters', o => { o.schedule_catalog.steps[10].status = 200; }],
  ['a stale cursor that continues', o => { o.schedule_catalog.steps[41].response.reason = 'schedule_page_token_filter_mismatch'; }],
  ['a page size measured as a number', o => { o.schedule_catalog.steps[14].response.errors.page_size.push('The page size field must be at least 1.'); }],
  ['a search attribute predicate that matches', o => { o.schedule_catalog.steps[36].response.schedules.push(o.schedule_catalog.steps[4].response.schedules[0]); }],
  ['an action replaced instead of merged', o => { delete o.schedule_catalog.steps[49].response.action.input; }],
  ['other changed fields', o => { o.schedule_catalog.steps[50].response.events.at(-1).payload.changed_fields.pop(); }],
  ['a fire time in UTC instead of the zone', o => { o.schedule_catalog.steps[57].response.results[0].fire_time = '2026-03-27T02:00:00+00:00'; }],
  ['an occurrence at the exclusive end', o => { o.schedule_catalog.steps[57].response.results.push({fire_time: '2026-03-30T06:00:00+00:00', outcome: 'started', workflow_id: 'x'}); }],
  ['a skip policy that starts every occurrence', o => { o.schedule_catalog.steps[61].response.results[1].outcome = 'started'; }],
  ['a quota that does not end the backfill', o => { o.schedule_catalog.steps[66].response.fires_attempted = 5; }],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation);
    assert.throws(() => checkScheduleCatalog(fixture, observation, workflowId));
  });
}
