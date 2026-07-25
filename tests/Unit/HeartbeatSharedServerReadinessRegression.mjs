import assert from 'node:assert/strict';
import test from 'node:test';

import {
  HeartbeatReadinessTimeoutError,
  classifySharedServerStartup,
  parsePublishedPortBindings,
  selectLoopbackPublishedEndpoint,
  waitForAuthenticatedReadiness,
} from '../../scripts/conformance/heartbeat-shared-readiness.mjs';

function healthyContainer(port = 48123) {
  return {
    State: {
      Status: 'running',
      Running: true,
      Health: { Status: 'healthy' },
    },
    NetworkSettings: {
      Ports: {
        '8080/tcp': [
          { HostIp: '0.0.0.0', HostPort: String(port) },
          { HostIp: '::', HostPort: String(port) },
        ],
      },
    },
  };
}

test('authenticated readiness retries a refused host connection and then succeeds', async () => {
  let monotonicMilliseconds = 0;
  const requests = [];

  const readiness = await waitForAuthenticatedReadiness({
    url: new URL('http://127.0.0.1:48123/api/ready'),
    token: 'control-token',
    timeoutMs: 100,
    attemptTimeoutMs: 20,
    retryIntervalMs: 10,
    monotonicNow: () => monotonicMilliseconds,
    sleep: async (milliseconds) => {
      monotonicMilliseconds += milliseconds;
    },
    fetchImpl: async (url, options) => {
      requests.push({ url: String(url), options });
      if (requests.length === 1) {
        throw Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:48123'), {
          code: 'ECONNREFUSED',
        });
      }
      return { ok: true, status: 200, statusText: 'OK' };
    },
  });

  assert.equal(readiness.status, 200);
  assert.equal(readiness.attempts, 2);
  assert.equal(readiness.elapsed_ms, 10);
  assert.equal(requests.length, 2);
  assert.equal(requests[0].url, 'http://127.0.0.1:48123/api/ready');
  for (const request of requests) {
    assert.equal(request.options.headers.Accept, 'application/json');
    assert.equal(request.options.headers.Authorization, 'Bearer control-token');
    assert.equal(request.options.signal instanceof AbortSignal, true);
  }
});

test('authenticated readiness stops at its monotonic deadline with the final response', async () => {
  let monotonicMilliseconds = 0;
  let attempts = 0;

  await assert.rejects(
    waitForAuthenticatedReadiness({
      url: new URL('http://127.0.0.1:48124/api/ready'),
      token: 'control-token',
      timeoutMs: 90,
      attemptTimeoutMs: 20,
      retryIntervalMs: 30,
      monotonicNow: () => monotonicMilliseconds,
      sleep: async (milliseconds) => {
        monotonicMilliseconds += milliseconds;
      },
      fetchImpl: async () => {
        attempts += 1;
        return { ok: false, status: 503, statusText: 'Service Unavailable' };
      },
    }),
    (error) => {
      assert.equal(error instanceof HeartbeatReadinessTimeoutError, true);
      assert.deepEqual(error.readiness, {
        timeout_ms: 90,
        attempts: 3,
        elapsed_ms: 90,
        final_status: 503,
        final_error: 'HTTP 503 Service Unavailable',
      });
      assert.match(error.message, /final status=503/);
      assert.match(error.message, /final error=HTTP 503 Service Unavailable/);
      return true;
    },
  );

  assert.equal(attempts, 3);
  assert.equal(monotonicMilliseconds, 90);
});

test('authenticated readiness bounds repeated host connection refusal', async () => {
  let monotonicMilliseconds = 0;
  let attempts = 0;

  await assert.rejects(
    waitForAuthenticatedReadiness({
      url: new URL('http://127.0.0.1:48125/api/ready'),
      token: 'control-token',
      timeoutMs: 75,
      attemptTimeoutMs: 20,
      retryIntervalMs: 25,
      monotonicNow: () => monotonicMilliseconds,
      sleep: async (milliseconds) => {
        monotonicMilliseconds += milliseconds;
      },
      fetchImpl: async () => {
        attempts += 1;
        throw Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:48125'), {
          code: 'ECONNREFUSED',
        });
      },
    }),
    (error) => {
      assert.equal(error instanceof HeartbeatReadinessTimeoutError, true);
      assert.deepEqual(error.readiness, {
        timeout_ms: 75,
        attempts: 3,
        elapsed_ms: 75,
        final_status: null,
        final_error: 'Error [ECONNREFUSED]: connect ECONNREFUSED 127.0.0.1:48125',
      });
      assert.match(error.message, /final status=none/);
      assert.match(error.message, /ECONNREFUSED/);
      return true;
    },
  );

  assert.equal(attempts, 3);
  assert.equal(monotonicMilliseconds, 75);
});

test('published port evidence selects the real loopback endpoint', () => {
  const output = '0.0.0.0:48123\n[::]:48123\n';

  assert.deepEqual(parsePublishedPortBindings(output), [
    { host: '0.0.0.0', port: 48123 },
    { host: '::', port: 48123 },
  ]);
  assert.deepEqual(selectLoopbackPublishedEndpoint(output, 48123), {
    host_url: 'http://127.0.0.1:48123',
    port: 48123,
    bindings: [
      { host: '0.0.0.0', port: 48123 },
      { host: '::', port: 48123 },
    ],
  });
  assert.throws(
    () => selectLoopbackPublishedEndpoint('', 48123),
    /did not report a published server port/,
  );
  assert.throws(
    () => selectLoopbackPublishedEndpoint('0.0.0.0:48124', 48123),
    /instead of requested port 48123/,
  );
});

test('startup diagnosis distinguishes container, port publication, and slow host bind', () => {
  const composePort = { status: 0, stdout: '0.0.0.0:48123\n[::]:48123\n' };

  assert.deepEqual(classifySharedServerStartup({
    container: {
      ...healthyContainer(),
      State: { Status: 'exited', Running: false, ExitCode: 1 },
    },
    composePort,
    expectedPort: 48123,
  }), {
    kind: 'container_failure',
    reason: 'server container state is exited',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort: { status: 0, stdout: '' },
    expectedPort: 48123,
  }), {
    kind: 'published_port_failure',
    reason: 'Compose reported no published server port',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readiness: {
      final_status: null,
      final_error: 'TypeError: fetch failed <- Error [ECONNREFUSED]: connect refused',
    },
  }), {
    kind: 'host_bind_timeout',
    reason: 'container health and published-port metadata passed but the host endpoint never bound',
  });

  assert.deepEqual(classifySharedServerStartup({
    container: healthyContainer(),
    composePort,
    expectedPort: 48123,
    readiness: {
      final_status: 503,
      final_error: 'HTTP 503 Service Unavailable',
    },
  }), {
    kind: 'readiness_response_failure',
    reason: 'host endpoint last returned HTTP 503',
  });
});
