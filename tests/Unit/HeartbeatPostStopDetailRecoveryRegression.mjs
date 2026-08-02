import assert from 'node:assert/strict';
import test from 'node:test';

import {
  ControlPlaneHttpError,
  ControlPlaneTransportError,
} from '../../scripts/conformance/heartbeat-final-visibility.mjs';
import {
  PersistentPostStopDetailTransportError,
  PostStopDetailHttpError,
  persistentPostStopDetailEvidence,
  recoverPostStopWorkerDetail,
  semanticPostStopDetailEvidence,
} from '../../scripts/conformance/heartbeat-post-stop-detail.mjs';
import { workerShutdownBoundary } from '../../scripts/conformance/heartbeat-stale-transition.mjs';

function refusedDetailRequest(attempt) {
  const connection = Object.assign(new Error('connect ECONNREFUSED 127.0.0.1:48123'), {
    code: 'ECONNREFUSED',
    syscall: 'connect',
    address: '127.0.0.1',
    port: 48123,
  });
  return new ControlPlaneTransportError(
    'GET',
    'http://127.0.0.1:48123/api/workers/heartbeat-python-stale',
    new TypeError('fetch failed', { cause: connection }),
    `2026-08-02T15:40:0${attempt}.000Z`,
  );
}

function observedClock(startMilliseconds = Date.parse('2026-08-02T15:40:15.100Z')) {
  let milliseconds = startMilliseconds;
  return () => {
    const value = new Date(milliseconds).toISOString();
    milliseconds += 100;
    return value;
  };
}

function confirmedShutdown() {
  return {
    worker_id: 'heartbeat-python-stale',
    stop_requested_at: '2026-08-02T15:40:14.000Z',
    stopped_at: '2026-08-02T15:40:15.000Z',
    stop_confirmation: {
      docker_stop_exit_code: 0,
      container_state: { status: 'exited', running: false, exit_code: 137 },
    },
    last_sdk_heartbeat_acknowledgement_observed_at: '2026-08-02T15:40:14.250Z',
    last_sdk_heartbeat_acknowledgement: { stale_after_seconds: 7 },
  };
}

function healthyServerDiagnostics() {
  return {
    container: {
      present: true,
      state: {
        status: 'running',
        running: true,
        restarting: false,
        exit_code: 0,
        health: { status: 'healthy' },
      },
      restart_count: 0,
    },
    readiness_probe: { ok: true, status: 200 },
    logs: { artifact: 'server-container.log', tail: 'server remained healthy' },
  };
}

async function persistentTransportError() {
  try {
    await recoverPostStopWorkerDetail({
      capture: async () => {
        throw refusedDetailRequest(1);
      },
      maxAttempts: 3,
      retryDelayMs: 250,
      wait: async () => {},
      observedAt: observedClock(),
    });
    assert.fail('persistent post-stop detail transport must remain non-passing');
  } catch (error) {
    assert.ok(error instanceof PersistentPostStopDetailTransportError);
    return error;
  }
}

test('a transient first post-stop detail failure recovers without moving the causal heartbeat anchor', async () => {
  let calls = 0;
  const recovered = await recoverPostStopWorkerDetail({
    capture: async () => {
      calls += 1;
      if (calls === 1) throw refusedDetailRequest(calls);
      return {
        worker_id: 'heartbeat-python-stale',
        last_heartbeat_at: '2026-08-02T15:40:14.000Z',
        stale_after_seconds: 7,
      };
    },
    maxAttempts: 3,
    retryDelayMs: 250,
    wait: async () => {},
    observedAt: observedClock(),
  });

  const shutdown = confirmedShutdown();
  const boundary = workerShutdownBoundary({
    stopRequestedAt: shutdown.stop_requested_at,
    stoppedAt: shutdown.stopped_at,
    workerDetailObservedAt: recovered.workerDetailObservedAt,
    workerDetail: recovered.workerDetail,
    finalHeartbeatRecord: {
      observed_at: shutdown.last_sdk_heartbeat_acknowledgement_observed_at,
      acknowledgement: shutdown.last_sdk_heartbeat_acknowledgement,
    },
    stopConfirmation: shutdown.stop_confirmation,
  });

  assert.equal(calls, 2);
  assert.equal(recovered.recovery.outcome, 'recovered');
  assert.equal(recovered.recovery.focused_read_only, true);
  assert.equal(recovered.recovery.shared_wave_retried, false);
  assert.deepEqual(recovered.recovery.attempts.map((attempt) => attempt.outcome), [
    'transport_error',
    'success',
  ]);
  assert.ok(recovered.recovery.attempts.every((attempt) => (
    Date.parse(attempt.finished_at) >= Date.parse(attempt.started_at)
  )));
  assert.equal(boundary.final_accepted_heartbeat_at, '2026-08-02T15:40:14.000Z');
  assert.equal(boundary.advertised_stale_after_seconds, 7);
});

test('persistent transport loss with no server unavailability proof remains a retained runner gap', async () => {
  const error = await persistentTransportError();
  const evidence = persistentPostStopDetailEvidence({
    error,
    serverDiagnostics: healthyServerDiagnostics(),
    shutdown: confirmedShutdown(),
  });

  assert.equal(evidence.classification, 'runner-transport-gap');
  assert.equal(evidence.owning_surface, 'conformance_harness');
  assert.equal(evidence.runner_blocked, true);
  assert.equal(evidence.post_stop_worker_detail_transport.attempts.length, 3);
  assert.equal(evidence.post_stop_worker_detail_transport.max_attempts, 3);
  assert.equal(evidence.post_stop_worker_detail_transport.retry_delay_ms, 250);
  assert.equal(evidence.post_stop_worker_detail_transport.focused_read_only, true);
  assert.equal(evidence.post_stop_worker_detail_transport.shared_wave_retried, false);
  assert.equal(
    evidence.post_stop_worker_detail_transport.underlying_connection_cause.code,
    'ECONNREFUSED',
  );
  assert.equal(evidence.server_diagnostics.container.state.health.status, 'healthy');
});

test('retained container or readiness state converts transport loss into availability evidence', async () => {
  const error = await persistentTransportError();
  for (const serverDiagnostics of [
    {
      container: {
        present: true,
        state: {
          status: 'exited',
          running: false,
          restarting: false,
          exit_code: 137,
          health: { status: null },
        },
      },
      logs: { artifact: 'server-container.log', tail: 'server exited' },
    },
    {
      ...healthyServerDiagnostics(),
      readiness_probe: { ok: false, status: 503, body: 'not ready' },
    },
  ]) {
    const evidence = persistentPostStopDetailEvidence({
      error,
      serverDiagnostics,
      shutdown: confirmedShutdown(),
    });

    assert.equal(evidence.classification, 'standalone-server-availability-gap');
    assert.equal(evidence.owning_surface, 'server');
    assert.equal(evidence.runner_blocked, false);
  }
});

test('semantic HTTP failures stop transport recovery and remain product evidence', async () => {
  let calls = 0;
  let semanticError;
  try {
    await recoverPostStopWorkerDetail({
      capture: async () => {
        calls += 1;
        if (calls === 1) throw refusedDetailRequest(calls);
        throw new ControlPlaneHttpError(
          'GET',
          'http://127.0.0.1:48123/api/workers/heartbeat-python-stale',
          503,
          { error: 'worker detail unavailable' },
          '2026-08-02T15:40:15.150Z',
        );
      },
      maxAttempts: 3,
      retryDelayMs: 250,
      wait: async () => {},
      observedAt: observedClock(),
    });
    assert.fail('semantic worker-detail failures must remain product evidence');
  } catch (error) {
    assert.ok(error instanceof PostStopDetailHttpError);
    semanticError = error;
  }

  const evidence = semanticPostStopDetailEvidence({
    error: semanticError,
    serverDiagnostics: healthyServerDiagnostics(),
    shutdown: confirmedShutdown(),
  });
  assert.equal(calls, 2);
  assert.deepEqual(semanticError.attempts.map((attempt) => attempt.outcome), [
    'transport_error',
    'http_error',
  ]);
  assert.equal(semanticError.response.status, 503);
  assert.equal(evidence.classification, 'standalone-server-worker-detail-http-gap');
  assert.equal(evidence.owning_surface, 'server');
  assert.equal(evidence.runner_blocked, false);
});
