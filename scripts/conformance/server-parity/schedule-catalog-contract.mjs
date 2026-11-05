import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {normalized} from './schedule-policies-contract.mjs';

// Published PHP's reviewed, normalized responses to every step.
const EXPECTED = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityExpected/schedule-catalog.json', import.meta.url)));

/// The reviewed status of each step, in order.
export const STATUSES = [201, 201, 201, 201, 200, 200, 422, 200, 422, 200, 409, 200, 422, 422, 422, 422, 400, 400, 422, 422,
  200, 200, 200, 200, 200, 422, 422, 422, 422, 422, 422, 422, 422, 422, 200, 201, 200, 422, 200, 200, 200, 409, 404,
  200, 200, 200, 200, 200, 422, 200, 200, 200, 201, 422, 422, 422, 422, 200, 200, 200, 201, 200, 200, 201, 200, 201,
  200, 404, 200, 404, 200, 200];

/// PHP's fire times for each backfill request, in order. Normalization
/// hides times, so these are checked raw.
const FIRE_TIMES = [[], [], [], [],
  ['2026-03-27T03:00:00+01:00', '2026-03-27T06:00:00+00:00', '2026-03-28T03:00:00+01:00', '2026-03-28T06:00:00+00:00',
    '2026-03-29T03:00:00+02:00', '2026-03-29T06:00:00+00:00', '2026-03-30T03:00:00+02:00'],
  ['2026-03-27T03:00:00+01:00', '2026-03-27T06:00:00+00:00', '2026-03-28T03:00:00+01:00', '2026-03-28T06:00:00+00:00',
    '2026-03-29T03:00:00+02:00', '2026-03-29T06:00:00+00:00', '2026-03-30T03:00:00+02:00', '2026-03-30T06:00:00+00:00'],
  ['2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00', '2026-01-03T00:00:00+00:00'],
  ['2026-02-01T00:00:00+00:00', '2026-02-02T00:00:00+00:00'],
  ['2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00', '2026-01-03T00:00:00+00:00'],
  ['2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00'],
  ['2026-01-01T03:00:00+01:00', '2026-01-02T03:00:00+01:00'], []];

const ids = step => step.response.schedules.map(item => item.schedule_id);

function checkedLastSkip(item, skipped, raw) {
  assert.ok(Object.hasOwn(item, 'last_skip_reason') && Object.hasOwn(item, 'last_skipped_at'),
    'complete schedule last-skip pair');
  if (skipped === 0) {
    assert.deepStrictEqual([item.last_skip_reason, item.last_skipped_at], [null, null],
      'new fixture schedules have no stored skip');
    return;
  }
  // These causes follow the fixture's paused, repeated-occurrence and overlap requests.
  const reasons = {beta: 'status_not_triggerable', bf: 'already_fired_occurrence', 'bf-skip': 'overlap_policy_skip'};
  assert.ok(Object.hasOwn(reasons, item.schedule_id), 'fixture has a known skip cause');
  assert.equal(item.last_skip_reason, reasons[item.schedule_id], 'last skip retains its complete cause');
  assert.match(item.last_skipped_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/,
    'last skip has six fractional digits and UTC Z');
  const instant = new Date(item.last_skipped_at);
  assert.ok(Number.isFinite(instant.getTime()), 'last skip is an actual instant');
  assert.equal(instant.toISOString().slice(0, 19), item.last_skipped_at.slice(0, 19),
    'last skip has valid calendar fields');
  const audit = raw.find(step => step.request === `GET schedules/${item.schedule_id}/history?limit=20`);
  if (audit) {
    const event = audit.response.events.filter(event => event.event_type === 'ScheduleTriggerSkipped').at(-1);
    assert.equal(item.last_skip_reason, event.payload.reason, 'last skip agrees with retained audit cause');
    // The existing audit writer stores whole seconds. Exact microseconds are
    // compared between list/describe and the stored row in the backend suites.
    assert.equal(item.last_skipped_at.slice(0, 19), event.payload.last_skipped_at.slice(0, 19),
      'later successful backfills preserve the earlier audited skip');
  }
}

// Keep the frozen PHP response contract intact. Native's additive fields are
// checked against the preceding HTTP backfill results before projecting them
// away for comparison with an older published PHP server.
function checkedListSummaries(raw, target) {
  const known = new Map();
  return raw.map(step => {
    if (step.status === 200 && step.request.endsWith('/backfill')) {
      const id = step.response.schedule_id;
      const state = known.get(id) ?? {failures: 0, skipped: 0, latest: null};
      for (const result of step.response.results) {
        if (result.outcome === 'started') state.latest = result.workflow_id;
        if (result.outcome === 'skipped') state.skipped++;
        if (result.outcome === 'failed') state.failures++;
      }
      known.set(id, state);
    }
    if (!Array.isArray(step.response.schedules)) return step;
    const schedules = step.response.schedules.map(item => {
      const fields = ['failures_count', 'skipped_trigger_count', 'latest_workflow_instance_id', 'current_run'];
      const present = fields.filter(field => Object.hasOwn(item, field));
      if (target !== 'rust' && present.length === 0) return item;
      assert.deepStrictEqual(present, fields, 'complete schedule list summary');
      const state = known.get(item.schedule_id) ?? {failures: 0, skipped: 0, latest: null};
      const skipFields = ['last_skip_reason', 'last_skipped_at'];
      if (target === 'rust' || skipFields.some(field => Object.hasOwn(item, field))) {
        checkedLastSkip(item, state.skipped, raw);
        fields.push(...skipFields);
      }
      assert.deepStrictEqual([item.failures_count, item.skipped_trigger_count, item.latest_workflow_instance_id],
        [state.failures, state.skipped, state.latest], 'summary counters and latest identity match preceding HTTP results');
      if (state.latest === null) assert.equal(item.current_run, null, 'unfired schedule has unknown current run');
      else {
        assert.match(state.latest, /^[0-9A-HJKMNP-TV-Z]{26}$/, 'complete generated workflow identity');
        assert.ok(item.current_run && typeof item.current_run === 'object', 'started schedule has current run');
        assert.match(item.current_run.run_id, /^[0-9A-HJKMNP-TV-Z]{26}$/, 'complete current run identity');
        assert.deepStrictEqual(item.current_run, {run_id: item.current_run.run_id, status: 'pending',
          status_bucket: 'running', is_terminal: false}, 'unclaimed backfill run remains pending');
        // The skip schedule also exposes both identities in its retained audit.
        const audit = raw.find(candidate => candidate.request === `GET schedules/${item.schedule_id}/history?limit=20`);
        if (audit) {
          const event = audit.response.events.filter(event => event.event_type === 'ScheduleTriggered').at(-1);
          assert.deepStrictEqual([state.latest, item.current_run.run_id],
            [event.workflow_instance_id, event.workflow_run_id], 'list current run matches retained trigger audit');
        }
      }
      return Object.fromEntries(Object.entries(item).filter(([field]) => !fields.includes(field)));
    });
    return {...step, response: {...step.response, schedules}};
  });
}

export function checkScheduleCatalog(fixture, observation, workflowId, target) {
  const state = observation.schedule_catalog;
  assert.ok(state && typeof state === 'object', 'actual schedule catalog observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_schedules'},
      'embedded schedule surface explicitly inapplicable');
    return {scope: 'schedule_catalog'};
  }
  assert.ok(Number.isInteger(state.attempts) && state.attempts >= 1 && state.attempts <= 5, 'recorded sequence attempts');
  // A sequence replayed after PHP's SQLite lock defect runs in a new namespace.
  const namespace = `${workflowId}-catalog`.toLowerCase() + (state.attempts === 1 ? '' : `-r${state.attempts}`);
  assert.deepStrictEqual([state.applicable, state.namespace], [true, namespace], 'schedule catalog namespace');
  assert.ok(Number.isInteger(state.retries) && state.retries >= 0, 'recorded lock retries');
  const raw = state.steps;
  const names = {[namespace]: '@namespace', [state.queue]: '@queue'};
  const steps = checkedListSummaries(raw, target)
    .map(step => ({request: step.request, status: step.status, response: normalized(step.response, names)}));
  assert.deepStrictEqual(steps.map(step => step.status), STATUSES, 'schedule catalog step statuses');
  // Newest first, filtered and paged.
  assert.deepStrictEqual(ids(steps[4]), ['delta', 'gamma', 'beta', 'alpha'], 'newest schedules first');
  assert.deepStrictEqual(ids(steps[5]), ['beta'], 'status filter');
  assert.deepStrictEqual(ids(steps[7]), ['delta', 'gamma', 'alpha'], 'workflow type filter');
  assert.deepStrictEqual([ids(steps[9]), ids(steps[11])], [['delta', 'gamma'], ['beta', 'alpha']], 'two pages');
  assert.equal(typeof raw[9].response.next_page_token, 'string', 'continuation token');
  assert.equal(raw[11].response.next_page_token, null, 'last page');
  assert.deepStrictEqual([steps[10].response.reason, steps[10].response.last_safe_cursor.schedule_id],
    ['schedule_page_token_filter_mismatch', 'gamma'], 'token bound to its filters');
  assert.deepStrictEqual([steps[16].response.reason, steps[17].response.reason],
    ['malformed_schedule_page_token', 'malformed_schedule_page_token'], 'unsigned tokens');
  assert.deepStrictEqual(steps[14].response.errors, {page_size: ['The page size field must be an integer.']},
    'Laravel sizes a non-numeric value by its length');
  assert.deepStrictEqual(ids(steps[22]), ['gamma'], 'workflow type and task queue predicates');
  assert.deepStrictEqual(ids(steps[23]), ['gamma'], 'quoted AND inside a literal');
  assert.equal(steps[25].response.reason, 'unsupported_schedule_visibility_predicate', 'inequality refused');
  assert.equal(steps[27].response.reason, 'unsupported_schedule_visibility_field', 'unknown field refused');
  assert.deepStrictEqual([ids(steps[36]), ids(steps[38])], [[], []], 'search attribute predicates match no schedule');
  assert.equal(steps[41].response.reason, 'stale_schedule_page_token', 'deleted cursor');
  // Updates merge the stored action and record PHP's changed fields.
  const alpha = steps[49].response;
  assert.deepStrictEqual(alpha.action, {workflow_type: fixture.workflow_type, task_queue: 'catalog-z',
    input: {codec: 'avro', blob: fixture.schedule_catalog.input_blob}, execution_timeout_seconds: 60}, 'merged action');
  assert.deepStrictEqual([alpha.overlap_policy, alpha.state.note, alpha.memo], ['allow_all', 'changed', null], 'updated fields');
  assert.deepStrictEqual(steps[50].response.events.filter(event => event.event_type === 'ScheduleUpdated')
    .map(event => event.payload.changed_fields), [['spec', 'action', 'overlap_policy', 'jitter_seconds', 'note'],
    ['spec', 'action'], ['spec', 'action', 'memo', 'max_runs', 'remaining_actions'], ['spec', 'action', 'memo', 'search_attributes'],
    ['spec', 'action']], 'changed fields');
  assert.deepStrictEqual(ids(steps[51]), ['alpha'], 'updated task queue predicate');
  // Backfills.
  assert.deepStrictEqual(raw.filter(step => step.request.endsWith('/backfill'))
    .map(step => (step.response.results ?? []).map(result => result.fire_time)), FIRE_TIMES, 'backfill fire times');
  assert.equal(steps[55].response.reason, 'invalid_time_range', 'reversed range');
  assert.deepStrictEqual(steps[61].response.results.map(result => result.outcome), ['started', 'skipped', 'skipped'],
    'skip policy while the first backfilled run is open');
  assert.deepStrictEqual(steps[64].response.results.map(result => result.outcome), ['started', 'started', 'started'],
    'buffering backfills start every occurrence');
  assert.equal(steps[66].response.fires_attempted, 2, 'quota ends the backfill');
  assert.deepStrictEqual(steps, EXPECTED, 'every response matches the reviewed published PHP responses');
  return {scope: 'schedule_catalog'};
}
