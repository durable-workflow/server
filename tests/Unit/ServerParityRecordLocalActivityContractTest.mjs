import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkRecordLocalActivity} from '../../scripts/conformance/server-parity/record-local-activity-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/record-local-activity.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/record-local-activity-observation.json', import.meta.url)));
const id = 'pr1-record-local-activity';
const observation = (change = () => {}, base = recorded) => {
  const state = structuredClone(base);
  change(state);
  return {mode: 'http', record_local_activity: state};
};
const check = state => checkRecordLocalActivity(fixture, state, id);
// The intended answers: the normalizer sees each report as sent.
const intended = JSON.parse(JSON.stringify(fixture.record_local_activity.expected).replaceAll('{id}', id));
const events = (state, run) => state[run].activity_events;

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'record_local_activity'});
});

test('the normalizer\'s own answers on the reports as sent are accepted too', () => {
  assert.deepStrictEqual(check(observation(() => {}, intended)), {scope: 'record_local_activity'});
  assert.equal(intended.reordered_valid_report.status, 200);
  assert.deepStrictEqual(intended.reordered_attempts_report.response.errors, intended.invalid_retry_attempts.response.errors);
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', record_local_activity:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'record_local_activity'});
});

for (const [label, change, message] of [
  // Native advertised a bare flag and refused the command before this fixture.
  ['a bare local_activities flag', state => {
    state.cluster_info.local_activities = false;
  }, /record local activity cluster_info/],
  ['an unsupported command', state => {
    state.completed = {status: 422, response: {reason: 'unsupported_workflow_command', message: 'unsupported_workflow_command'}};
  }, /record local activity completed/],
  ['prepared flags that disagree', state => {
    state.cluster_info.prepared_flags.agree = false;
  }, /record local activity cluster_info/],
  ['the capability guard before ownership', state => {
    state.refused_wrong_owner = structuredClone(state.refused_capability);
  }, /record local activity refused_wrong_owner/],
  ['normalizer errors before ownership', state => {
    state.refused_wrong_owner_invalid_report = structuredClone(intended.invalid_report);
  }, /record local activity refused_wrong_owner_invalid_report/],
  ['only part of the published reordering defect', state => {
    state.reordered_valid_report = structuredClone(intended.reordered_valid_report);
  }, /record local activity reordered_attempts_report/],
  ['a reordered report that is accepted without its own errors', state => {
    Object.assign(state, structuredClone({reordered_valid_report: intended.reordered_valid_report,
      reordered_heartbeats_report: intended.reordered_heartbeats_report}));
  }, /record local activity reordered_attempts_report/],
  ['normalizer errors in another order', state => {
    const errors = Object.entries(state.invalid_report.response.errors).reverse();
    state.invalid_report.response.message = `${errors[0][1][0]} (and 5 more errors)`;
  }, /record local activity invalid_report/],
  ['lowercase attempt identities', state => {
    const text = JSON.stringify(state.completed_run).replaceAll(/(attempt-\d+):upper/g, '$1:lower');
    state.completed_run = JSON.parse(text);
  }, /record local activity completed_run/],
  ['attempt identities generated first attempt first', state => {
    state.completed_run.attempt_identity_order.reverse();
  }, /record local activity completed_run/],
  ['times walked forward from the first start', state => {
    events(state, 'completed_run')[1].payload.activity_attempt.started_at = '@0ms';
  }, /record local activity completed_run/],
  ['the command\'s queue instead of the run\'s', state => {
    events(state, 'completed_run')[0].payload.activity.queue = 'ignored-queue';
  }, /record local activity completed_run/],
  ['empty heartbeat details kept as an object', state => {
    const heartbeat = events(state, 'failed_run').find(event => event.event_type === 'ActivityHeartbeatRecorded'
      && Array.isArray(event.payload.progress));
    heartbeat.payload.progress = {};
  }, /record local activity failed_run/],
  ['a synthesized 1.18 attempt with a worker identity', state => {
    events(state, 'protocol_1_18_run')[1].payload.worker_attempt_id = 'w-1';
  }, /record local activity protocol_1_18_run/],
  ['a timed out activity recorded as failed', state => {
    const timedOut = events(state, 'failed_run').find(event => event.event_type === 'ActivityTimedOut');
    timedOut.event_type = 'ActivityFailed';
  }, /record local activity failed_run/],
  ['a missing step', state => {
    delete state.invalid_heartbeats;
  }, /record local activity invalid_heartbeats/],
  ['an extra step', state => {
    state.extra = {status: 200};
  }, /record local activity steps/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}

test('a task snapshot names the backend\'s own storage connection, one per observation', () => {
  for (const name of ['pgsql', 'mysql']) {
    const renamed = observation(state => {
      for (const run of ['completed_run', 'failed_run', 'protocol_1_18_run']) events(state, run)[0].payload.task.connection = name;
    });
    assert.deepStrictEqual(check(renamed), {scope: 'record_local_activity'});
  }
  const mixed = observation(state => {
    events(state, 'failed_run')[0].payload.task.connection = 'pgsql';
  });
  assert.throws(() => check(mixed), /actual names one storage connection: sqlite, pgsql/);
  const unknown = observation(state => {
    for (const run of ['completed_run', 'failed_run', 'protocol_1_18_run']) events(state, run)[0].payload.task.connection = 'redis';
  });
  assert.throws(() => check(unknown), /actual storage connection redis/);
});
