import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkChildRetries} from '../../scripts/conformance/server-parity/child-retries-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/child-retries.json', import.meta.url), 'utf8'));
const expected = fixture.child_retries.expected;
const observation = () => structuredClone({mode: 'http', child_retries: {applicable: true, ...expected}});
const cases = Object.keys(fixture.child_retries.cases).length;
const events = name => expected[name].parent_events.map(event => event.event_type);

test('the published answers pass', () => {
  assert.deepStrictEqual(checkChildRetries(fixture, observation()), {scope: 'child_retries', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkChildRetries(fixture,
    {mode: 'embedded', child_retries: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'child_retries', cases});
});

test('a failed or timed-out child is retried while attempts remain and the failure is retryable', () => {
  const started = ['ChildWorkflowScheduled', 'ChildRunStarted', 'ChildRunStarted'];
  assert.deepStrictEqual(events('retry_then_complete'), [...started, 'ChildRunCompleted']);
  assert.deepStrictEqual(events('attempts_exhausted'), [...started, 'ChildRunFailed']);
  assert.deepStrictEqual(events('run_timeout_retried'), [...started, 'ChildRunFailed']);
  for (const name of ['non_retryable_type', 'non_retryable_flag', 'empty_policy']) {
    assert.deepStrictEqual(events(name), ['ChildWorkflowScheduled', 'ChildRunStarted', 'ChildRunFailed'], name);
  }
  const retry = expected.retry_then_complete.parent_events[2];
  assert.deepStrictEqual([retry.child_run_number, retry.retry_attempt, retry.retry_backoff_seconds], [2, 2, 1]);
  assert.equal(retry.child_call_id, expected.retry_then_complete.parent_events[0].child_call_id, 'the retry keeps the call');
  assert.notEqual(retry.workflow_link_id, retry.child_call_id, 'the retry has its own link');
  assert.equal(expected.retry_then_complete.described_during_retry.wait_kind, 'child');
  assert.deepStrictEqual(expected.retry_then_complete.retry_deadlines,
    [{execution_deadline_same: true, run_deadline_not_earlier: true}]);
  assert.deepStrictEqual(expected.run_timeout_retried.attempts.map(attempt => attempt.answer.reason),
    ['run_timed_out', 'run_timed_out']);
});

test('an empty policy records nothing and timeouts alone record no retry policy', () => {
  assert.equal(expected.empty_policy.parent_events[0].retry_policy, null);
  assert.equal(expected.timeouts_only.parent_events[0].retry_policy, null);
  assert.deepStrictEqual(expected.timeouts_only.parent_events[0].timeout_policy,
    {snapshot_version: 1, execution_timeout_seconds: 600, run_timeout_seconds: 300});
});

for (const [name, mutate] of [
  ['a refused child option', state => { state.retry_then_complete.start = {status: 422, reason: 'unsupported_request_field',
    run_status: null, outcome: null, recorded: null}; }],
  ['a child failed without its retry', state => { state.attempts_exhausted.parent_events.splice(2, 1); }],
  ['a retry of a non-retryable type', state => { state.non_retryable_type.child_runs.push({run_id: '{id:9}', run_number: 2,
    status: 'pending', is_current_run: true}); }],
  ['a retry that renews the execution deadline', state => {
    state.retry_then_complete.retry_deadlines[0].execution_deadline_same = false; }],
  ['a parent described without a wait during the retry', state => {
    state.retry_then_complete.described_during_retry.wait_kind = null; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.child_retries);
  assert.throws(() => checkChildRetries(fixture, raw));
});
