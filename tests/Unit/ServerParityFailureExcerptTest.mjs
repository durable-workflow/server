import assert from 'node:assert/strict';
import test from 'node:test';
import {failureExcerpt} from '../../scripts/conformance/server-parity/failure-excerpt.mjs';

const poll = (status, extra = {}) => ({method: 'POST', path: '/api/worker/workflow-tasks/poll', status,
  request: {worker_id: 'w1', poll_request_id: 'p1', task_queue: 'q', secret_input: 'payload-bytes'},
  response: {reason: 'lock pressure', nested: {method: 'GET', path: '/not-a-request', status: 200}},
  request_body: '{"secret_input":"payload-bytes"}', response_body: 'body-bytes', client_cancelled: false,
  transport_error: null, ...extra});

test('a failed case lists its requests in order with identity and transport flags, never bodies', () => {
  const text = failureExcerpt([{fixture_id: 'legacy-one-marker-control', outcome: 'product-fail',
    error: 'same original poll recovers\nsecond line', observation: {legacy_marker: {phases: [
      {role: 'original', requests: [poll(503, {response_retry_after: '2'}), poll(200)]},
      {role: 'replacement', requests: [{method: 'DELETE', path: '/api/worker/registrations/w1', status: 200,
        request: null, client_cancelled: true}]}]}}}]);
  assert.equal(text, [
    '== legacy-one-marker-control (product-fail): same original poll recovers',
    'observation.legacy_marker.phases[0].requests[0] POST /api/worker/workflow-tasks/poll 503 response_retry_after=2 worker_id=w1 poll_request_id=p1 task_queue=q',
    'observation.legacy_marker.phases[0].requests[1] POST /api/worker/workflow-tasks/poll 200 worker_id=w1 poll_request_id=p1 task_queue=q',
    'observation.legacy_marker.phases[1].requests[0] DELETE /api/worker/registrations/w1 200 client_cancelled=true',
    ''].join('\n'));
  assert(!text.includes('payload-bytes') && !text.includes('body-bytes') && !text.includes('/not-a-request'));
});

test('the outline is bounded per case and in total', () => {
  const many = Array.from({length: 50}, () => poll(200));
  const one = failureExcerpt([{fixture_id: 'a', outcome: 'product-fail', observation: {requests: many}}], {linesPerCase: 10});
  assert.equal(one.split('\n').filter(l => l.startsWith('observation')).length, 10);
  assert.match(one, /more requests omitted after 10/);
  const cases = Array.from({length: 20}, (_, i) => ({fixture_id: `case-${i}`, outcome: 'product-fail', observation: {requests: many}}));
  const all = failureExcerpt(cases, {maxBytes: 8 * 1024});
  assert(Buffer.byteLength(all) <= 8 * 1024 + 100);
  assert.match(all, /further failed cases omitted at 8192 bytes/);
});

test('a case without an observation still names its error', () => {
  assert.equal(failureExcerpt([{fixture_id: 'x', outcome: 'runner-blocked', error: 'boom'}]), '== x (runner-blocked): boom\n');
});

test('stored workflow tasks are listed by counters and clocks, never payloads or errors', () => {
  const text = failureExcerpt([{fixture_id: 'signal-selected-run-sdk-rust-php', outcome: 'product-fail', error: '1 !== 0',
    observation: {mode: 'embedded', pairs: [{final_stored: {tables: {
      workflow_tasks: {rows: [{id: '01TASK', workflow_run_id: '01RUN', task_type: 'workflow', status: 'completed',
        attempt_count: 1, repair_count: 1, available_at: '2026-10-25 06:40:01', last_dispatched_at: '2026-10-25 06:40:07',
        payload: {secret: 'payload-bytes'}, last_error: 'error-text', lease_owner: 'owner-name'}]},
      workflow_commands: {rows: [{method: 'POST', path: '/not-a-request', status: 200}]}}}}]}}]);
  assert.equal(text, [
    '== signal-selected-run-sdk-rust-php (product-fail): 1 !== 0',
    'observation.pairs[0].final_stored.tables.workflow_tasks[0] task 01TASK workflow_run_id=01RUN task_type=workflow status=completed attempt_count=1 repair_count=1 available_at=2026-10-25 06:40:01 last_dispatched_at=2026-10-25 06:40:07',
    ''].join('\n'));
  assert(!text.includes('payload-bytes') && !text.includes('error-text') && !text.includes('owner-name') && !text.includes('/not-a-request'));
});

test('labelled steps keep their request label and status, never their responses', () => {
  const text = failureExcerpt([{fixture_id: 'signals-before-worker', outcome: 'product-fail', error: 'step statuses',
    observation: {signals_before_worker: {steps: [
      {request: 'POST /api/workflows', status: 201, response: {workflow_id: 'secret-run'}},
      {request: 'POST /api/workflows/w/signal/s', status: 500, response: {message: 'Server Error', token: 'secret-token'}},
      {request: 'not a label', status: 200}, {request: 'GET /api/x', status: '200'}]}}}]);
  assert.equal(text, [
    '== signals-before-worker (product-fail): step statuses',
    'observation.signals_before_worker.steps[0] POST /api/workflows 201',
    'observation.signals_before_worker.steps[1] POST /api/workflows/w/signal/s 500',
    ''].join('\n'));
  assert(!text.includes('secret-run') && !text.includes('secret-token') && !text.includes('Server Error'));
});
