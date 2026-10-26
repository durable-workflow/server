// A poll the SIGKILLed original author had in flight can end before the
// observing proxy forwards it. The server never saw that request, so it
// carries no request bytes and no worker authority. It is admitted only for a
// fixture that declares it, only for a no-result poll the original author can
// have open, and only between the original's real registration and durable
// admission and the original's explicit retirement. The corrected-marker and
// corrected-clock contracts share this check.
import assert from 'node:assert/strict';

export function checkUnsentKilledAuthorPoll(wire, requests, spec, originalActor, workflowId) {
  assert.equal(spec.producer, 'python');
  assert.equal(spec.original_shutdown, 'SIGKILL');
  assert.equal(spec.cancel_unsent_original_polls, true);
  assert.equal(originalActor.author_exit_code, -9);
  assert.equal(wire.client_cancelled, true);
  assert.equal(wire.method, 'POST');
  assert(spec.cancelled_original_polls.some(kind => wire.path === `/api/worker/${kind}-tasks/poll`),
    'only killed original no-result polls can be cancelled');
  assert.equal(wire.status, 0);
  assert.equal(wire.response_body, '');
  assert.equal(wire.response_bytes_base64, '');
  assert.equal(wire.upstream_request_started, false);
  assert.equal(wire.request_body_complete, false);
  assert.equal(wire.request_body, '', 'unsent bytes establish no worker authority');
  const index = requests.indexOf(wire);
  assert(index >= 0, 'the poll is one of the recorded requests');
  const prior = requests.slice(0, index);
  const workerId = `${workflowId}:workflow:original`;
  assert(prior.some(item => item.path === '/api/worker/register' && item.status === 201
    && JSON.parse(item.request_body).worker_id === workerId), 'real original registration precedes cancellation');
  assert(prior.some(item => /^\/api\/worker\/workflow-tasks\/[^/]+\/complete$/.test(item.path)
    && item.status === 200 && JSON.parse(item.request_body).lease_owner === workerId
    && JSON.parse(item.response_body).recorded === true), 'original durable admission precedes cancellation');
  const retirement = requests.findIndex(item => item.method === 'DELETE'
    && item.path === '/api/worker/registrations/' + encodeURIComponent(workerId));
  assert(retirement > index, 'unsent killed-author poll precedes explicit retirement');
  return {...wire, request: null, response: null};
}
