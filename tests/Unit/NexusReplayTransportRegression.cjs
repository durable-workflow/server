'use strict';

const assert = require('node:assert/strict');
const http = require('node:http');
const {once} = require('node:events');
const test = require('node:test');

const {
  replayPostWithStaleSocketRecovery,
  staleSocketTransportError,
} = require('../../scripts/conformance/nexus-replay-transport.cjs');

const apiPath = '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute';

function socketError(code = 'UND_ERR_SOCKET') {
  const cause = new Error('other side closed');
  cause.code = code;
  const error = new TypeError('fetch failed');
  error.cause = cause;
  return error;
}

function requestBody(idempotencyKey = 'replay-idempotency-key') {
  return {
    arguments: {name: 'restart-replay'},
    caller_workflow_instance_id: 'tenant-a-replay',
    caller_workflow_run_id: 'run-replay',
    idempotency_key: idempotencyKey,
  };
}

function response(status, body = {}) {
  return {
    request: {method: 'POST', path: `/api${apiPath}`, namespace: 'shared', body: requestBody()},
    status,
    ok: status >= 200 && status < 300,
    body,
    raw_body: JSON.stringify(body),
  };
}

test('stale pooled replay retries once on a fresh connection without duplicating the call', async (t) => {
  const sockets = [];
  const callsByIdempotencyKey = new Map();
  let requestCount = 0;
  let invocationCount = 0;

  const server = http.createServer(async (request, serverResponse) => {
    const chunks = [];
    for await (const chunk of request) {
      chunks.push(chunk);
    }
    const payload = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    const key = payload.idempotency_key;
    const replay = callsByIdempotencyKey.has(key);
    if (!replay) {
      invocationCount += 1;
      callsByIdempotencyKey.set(key, {
        service_call_id: 'service-call-replay',
        resolved_target_reference: 'activity-replay-target',
      });
    }
    requestCount += 1;

    serverResponse.writeHead(200, {
      'Content-Type': 'application/json',
      Connection: 'keep-alive',
    });
    serverResponse.end(JSON.stringify({
      ...callsByIdempotencyKey.get(key),
      accepted: true,
      idempotent_replay: replay,
    }));
  });
  server.on('connection', (socket) => sockets.push(socket));
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(() => new Promise((resolve) => server.close(resolve)));

  const address = server.address();
  assert.equal(typeof address, 'object');
  const baseUrl = `http://127.0.0.1:${address.port}`;
  const token = 'secret-bearer-token-must-not-be-recorded';
  const body = requestBody();
  const initialResponse = await fetch(`${baseUrl}/api${apiPath}`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      'X-Namespace': 'shared',
    },
    body: JSON.stringify(body),
  });
  const initial = await initialResponse.json();
  assert.equal(sockets.length, 1, 'the initial Undici request should establish the pooled connection');

  const pooledSocketClosed = once(sockets[0], 'close');
  sockets[0].resetAndDestroy();
  await pooledSocketClosed;

  let pooledAttempts = 0;
  const recovered = await replayPostWithStaleSocketRecovery({
    baseUrl,
    token,
    namespace: 'shared',
    apiPath,
    body,
    pooledRequest: async (...arguments_) => {
      pooledAttempts += 1;
      assert.equal(arguments_[5], body, 'recovery must retain the exact request body');
      throw socketError();
    },
  });

  assert.equal(recovered.response.ok, true);
  assert.equal(recovered.response.body.idempotent_replay, true);
  assert.equal(recovered.response.body.service_call_id, initial.service_call_id);
  assert.equal(recovered.response.body.resolved_target_reference, initial.resolved_target_reference);
  assert.equal(pooledAttempts, 1);
  assert.equal(sockets.length, 2, 'recovery should establish exactly one fresh connection');
  assert.equal(requestCount, 2, 'the stale attempt must not reach the service');
  assert.equal(invocationCount, 1, 'the repeated idempotency key must not issue a duplicate invocation');
  assert.equal(recovered.transport.max_retries, 1);
  assert.equal(recovered.transport.retry_count, 1);
  assert.equal(recovered.transport.recovery_needed, true);
  assert.equal(recovered.transport.fresh_connection_used, true);
  assert.equal(recovered.transport.attempts.length, 2);
  assert.equal(recovered.transport.attempts[0].connection, 'pooled');
  assert.equal(recovered.transport.attempts[1].connection, 'fresh');
  assert.equal(
    recovered.transport.attempts[0].request_body_sha256,
    recovered.transport.attempts[1].request_body_sha256,
  );
  assert.equal(
    recovered.transport.attempts[0].idempotency_key_sha256,
    recovered.transport.attempts[1].idempotency_key_sha256,
  );
  assert.equal(JSON.stringify(recovered.transport).includes(token), false);
});

test('non-stale transport failures remain failures and are not retried', async () => {
  const error = new Error('permission denied');
  error.code = 'EACCES';
  let freshAttempts = 0;
  const result = await replayPostWithStaleSocketRecovery({
    baseUrl: 'http://127.0.0.1:1',
    token: 'secret-token',
    namespace: 'shared',
    apiPath,
    body: requestBody(),
    pooledRequest: async () => { throw error; },
    freshRequest: async () => { freshAttempts += 1; },
  });

  assert.equal(result.response.ok, false);
  assert.equal(result.response.status, 0);
  assert.equal(result.response.body.transport_error.code, 'EACCES');
  assert.equal(result.transport.retry_count, 0);
  assert.equal(result.transport.attempts.length, 1);
  assert.equal(freshAttempts, 0);
});

test('HTTP error responses remain failures and are not retried', async () => {
  let freshAttempts = 0;
  const result = await replayPostWithStaleSocketRecovery({
    baseUrl: 'http://127.0.0.1:1',
    token: 'secret-token',
    namespace: 'shared',
    apiPath,
    body: requestBody(),
    pooledRequest: async () => response(503, {error: 'unavailable'}),
    freshRequest: async () => { freshAttempts += 1; },
  });

  assert.equal(result.response.ok, false);
  assert.equal(result.response.status, 503);
  assert.equal(result.transport.attempts[0].outcome, 'http_error');
  assert.equal(result.transport.retry_count, 0);
  assert.equal(freshAttempts, 0);
});

test('a failed fresh attempt is not retried again', async () => {
  let freshAttempts = 0;
  const result = await replayPostWithStaleSocketRecovery({
    baseUrl: 'http://127.0.0.1:1',
    token: 'secret-token',
    namespace: 'shared',
    apiPath,
    body: requestBody(),
    pooledRequest: async () => { throw socketError(); },
    freshRequest: async () => {
      freshAttempts += 1;
      throw socketError('ECONNRESET');
    },
  });

  assert.equal(result.response.ok, false);
  assert.equal(result.transport.retry_count, 1);
  assert.equal(result.transport.attempts.length, 2);
  assert.equal(result.transport.transport_recovered, false);
  assert.equal(freshAttempts, 1);
});

test('only stale-socket transport codes are eligible for recovery', () => {
  for (const code of ['UND_ERR_SOCKET', 'ECONNRESET', 'EPIPE']) {
    assert.equal(staleSocketTransportError(socketError(code)), true, code);
  }
  for (const code of ['EACCES', 'ECONNREFUSED', 'UND_ERR_CONNECT_TIMEOUT']) {
    assert.equal(staleSocketTransportError(socketError(code)), false, code);
  }
});
