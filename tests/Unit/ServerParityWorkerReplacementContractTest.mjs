import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkWorkerReplacement} from '../../scripts/conformance/server-parity/worker-replacement-contract.mjs';

// A modeled observation shaped like published PHP's. It checks the contract
// itself; only actual recordings qualify a server.
const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/worker-replacement-activity-lease.json', import.meta.url)));
const id = 'replacement-reference';
const worker = `${id}-replaced-worker`;
const [task, first, second] = ['task-1', 'attempt-1', 'attempt-2'];
const claim = (attempt, number) => ({task_id: task, activity_attempt_id: attempt, attempt_number: number, lease_owner: worker});
const event = (event_type, payload = {}) => ({event_type, payload});
const history = [event('StartAccepted'), event('WorkflowStarted'), event('ActivityScheduled'),
  event('ActivityStarted', {activity_attempt_id: first, attempt_number: 1}),
  event('ActivityStarted', {activity_attempt_id: second, attempt_number: 2}),
  event('ActivityCompleted', {activity_attempt_id: second, attempt_number: 2}), event('WorkflowCompleted')];
const observation = () => structuredClone({mode: 'http', worker_replacement: {applicable: true, worker_id: worker,
  task_queue: `${id}-replacement`, peer_workflow_id: `${id}-replacement-peer`,
  registration: {worker_id: worker, registered: true}, replacement_registration: {worker_id: worker, registered: true},
  workflow_lease_seconds: 59.8, original_claim: claim(first, 1), replacement_claim: claim(second, 2),
  history_after_replacement: history.slice(0, 4),
  stale_completion: {status: 409, response: {task_id: task, activity_attempt_id: first, outcome: 'completed', recorded: false,
    reason: 'stale_attempt', next_task_id: null, lease_owner: worker, activity_status: 'running', attempt_status: 'expired',
    task_status: 'leased', protocol_version: '1.20'}},
  completion: {status: 200, response: {task_id: task, activity_attempt_id: second, outcome: 'completed', recorded: true,
    reason: null, next_task_id: 'workflow-task'}},
  history}});

test('worker replacement accepts the published release and redelivery', () => {
  assert.deepStrictEqual(checkWorkerReplacement(fixture, observation(), id),
    {scope: 'worker_replacement', expected_events: fixture.worker_replacement.expected_events});
});

test('embedded worker replacement is explicitly inapplicable', () => {
  assert.deepStrictEqual(checkWorkerReplacement(fixture, {mode: 'embedded', worker_replacement:
    {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'}}, id),
  checkWorkerReplacement(fixture, observation(), id));
});

for (const [label, mutate] of [
  ['a lease that waits out its expiry', state => { state.replacement_claim = null; }],
  ['a 30-second workflow task lease', state => { state.workflow_lease_seconds = 29.9; }],
  ['a different task', state => { state.replacement_claim.task_id = 'task-2'; }],
  ['a reused attempt number', state => { state.replacement_claim.attempt_number = 1; }],
  ['a reused attempt identity', state => { state.replacement_claim.activity_attempt_id = first; }],
  ['history recorded at release', state => { state.history_after_replacement.push(event('ActivityFailed')); }],
  ['an accepted stale completion', state => { state.stale_completion = {status: 200, response: {recorded: true}}; }],
  ['a bare stale refusal', state => { state.stale_completion.response = {reason: 'stale_attempt'}; }],
  ['an open original attempt', state => { state.stale_completion.response.attempt_status = 'running'; }],
  ['an unrecorded replacement', state => { state.completion.response.recorded = false; }],
  ['the original attempt completing', state => { state.history[5].payload.activity_attempt_id = first; }],
  ['a missing second start', state => { state.history.splice(4, 1); }],
]) test(`worker replacement rejects ${label}`, () => {
  const value = observation();
  mutate(value.worker_replacement);
  assert.throws(() => checkWorkerReplacement(fixture, value, id));
});
