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
