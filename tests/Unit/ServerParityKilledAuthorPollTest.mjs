// Server #754: a poll the SIGKILLed original author had in flight can end
// before the observing proxy forwards it. The corrected-marker and
// corrected-clock contracts admit it only through this shared check.
import test from 'node:test';
import assert from 'node:assert/strict';
import {checkUnsentKilledAuthorPoll} from '../../scripts/conformance/server-parity/killed-author-poll.mjs';

const workflowId = 'wf';
const worker = `${workflowId}:workflow:original`;
const spec = {producer: 'python', original_shutdown: 'SIGKILL', cancel_unsent_original_polls: true,
  cancelled_original_polls: ['workflow', 'activity', 'query']};
const actor = {author_exit_code: -9};

// The shape of the failing record in run 40206210357: registration, durable
// admission, the cancelled polls, then the original's explicit retirement.
function record() {
  const requests = [
    {method: 'POST', path: '/api/worker/register', status: 201, request_body: JSON.stringify({worker_id: worker})},
    {method: 'POST', path: '/api/worker/workflow-tasks/task-1/complete', status: 200,
      request_body: JSON.stringify({lease_owner: worker}), response_body: JSON.stringify({recorded: true})},
    {method: 'POST', path: '/api/worker/activity-tasks/poll', status: 0, client_cancelled: true,
      request_body_complete: false, upstream_request_started: false, request_body: '', response_body: '', response_bytes_base64: ''},
    {method: 'DELETE', path: `/api/worker/registrations/${encodeURIComponent(worker)}`, status: 200, request_body: ''},
  ];
  return {requests, poll: requests[2]};
}

const check = ({requests, poll}, overrides = {}) => checkUnsentKilledAuthorPoll(poll, requests,
  {...spec, ...overrides.spec}, {...actor, ...overrides.actor}, workflowId);

test('an unsent killed-author poll between registration, admission and retirement carries no request', () => {
  const result = check(record());
  assert.equal(result.request, null);
  assert.equal(result.response, null);
  for (const kind of ['workflow', 'query']) {
    const value = record();
    value.poll.path = `/api/worker/${kind}-tasks/poll`;
    assert.doesNotThrow(() => check(value), kind);
  }
});

test('anything the server could have seen, or any lifecycle outside the killed author, is refused', () => {
  const cases = {
    'received body': value => { value.poll.request_body_complete = true; },
    'invented request authority': value => { value.poll.request_body = '{}'; },
    'invented forwarding': value => { value.poll.upstream_request_started = true; },
    'invented response status': value => { value.poll.status = 200; },
    'invented response body': value => { value.poll.response_body = '{}'; },
    'not a cancellation': value => { value.poll.client_cancelled = false; },
    'result-bearing route': value => { value.poll.path = '/api/worker/workflow-tasks/task-1/complete'; },
    'missing original registration': value => { value.requests.splice(0, 1); },
    'another worker registered': value => { value.requests[0].request_body = JSON.stringify({worker_id: 'wf:workflow:other'}); },
    'missing durable admission': value => { value.requests.splice(1, 1); },
    'admission not recorded': value => { value.requests[1].response_body = JSON.stringify({recorded: false}); },
    'missing retirement': value => { value.requests.splice(3, 1); },
    'retirement before the poll': value => { value.requests.splice(1, 0, value.requests.splice(3, 1)[0]); },
    'poll not in the record': value => { value.poll = {...value.poll}; },
  };
  for (const [name, mutate] of Object.entries(cases)) {
    const value = record();
    mutate(value);
    assert.throws(() => check(value), undefined, name);
  }
  const specs = {
    'undeclared unsent cancellation': {spec: {cancel_unsent_original_polls: false}},
    'graceful shutdown': {spec: {original_shutdown: 'SIGTERM'}},
    'other producer': {spec: {producer: 'php'}},
    'author not killed': {actor: {author_exit_code: 0}},
    'poll kind not declared': {spec: {cancelled_original_polls: ['workflow']}},
  };
  for (const [name, overrides] of Object.entries(specs)) {
    assert.throws(() => check(record(), overrides), undefined, name);
  }
});
