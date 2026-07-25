import assert from 'node:assert/strict';
import test from 'node:test';

import {
  HeartbeatReadinessTimeoutError,
  waitForAuthenticatedReadiness,
} from '../../scripts/conformance/heartbeat-shared-readiness.mjs';

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
