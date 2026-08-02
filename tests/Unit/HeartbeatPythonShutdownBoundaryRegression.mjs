import assert from 'node:assert/strict';
import test from 'node:test';

import {
  refineWorkerShutdownBoundary,
  staleTransitionEvidence,
  workerShutdownBoundary,
} from '../../scripts/conformance/heartbeat-stale-transition.mjs';

function pythonShutdownBoundary() {
  return workerShutdownBoundary({
    stopRequestedAt: '2026-07-01T10:00:00Z',
    stoppedAt: '2026-07-01T10:00:15Z',
    workerDetailObservedAt: '2026-07-01T10:00:16Z',
    workerDetail: {
      worker_id: 'heartbeat-python-stale',
      last_heartbeat_at: '2026-07-01T10:00:14Z',
      stale_after_seconds: 7,
    },
    finalHeartbeatRecord: {
      event: 'worker_heartbeat',
      observed_at: '2026-07-01T10:00:14.250Z',
      acknowledgement: { stale_after_seconds: 7 },
    },
    stopConfirmation: {
      docker_stop_exit_code: 0,
      container_state: { status: 'exited', running: false, exit_code: 137 },
    },
  });
}

test('Python shutdown timing uses the final accepted heartbeat after a delayed stop', () => {
  const initialBoundary = {
    ...pythonShutdownBoundary(),
    worker_detail_observed_at: '2026-07-01T10:00:15.500Z',
    final_accepted_heartbeat_at: '2026-07-01T10:00:12Z',
  };
  const boundary = refineWorkerShutdownBoundary({
    shutdownBoundary: initialBoundary,
    workerDetailObservedAt: '2026-07-01T10:00:24Z',
    workerDetail: {
      last_heartbeat_at: '2026-07-01T10:00:14Z',
      stale_after_seconds: 7,
    },
  });
  const transition = staleTransitionEvidence({
    shutdownBoundary: boundary,
    observedStaleAt: '2026-07-01T10:00:24Z',
  });

  assert.equal(boundary.stop_duration_seconds, 15);
  assert.equal(boundary.final_accepted_heartbeat_at, '2026-07-01T10:00:14Z');
  assert.equal(boundary.advertised_stale_after_seconds, 7);
  assert.equal(transition.transition_elapsed_seconds, 10);
  assert.equal(transition.confirmed_stop_to_stale_seconds, 9);
  assert.equal(transition.bounded_max_seconds, 12);
  assert.equal(transition.within_bounded_window, true);
  assert.equal(
    (Date.parse(transition.observed_stale_at) - Date.parse(boundary.stop_requested_at)) / 1_000,
    24,
    'the pre-stop timestamp would have produced the intermittent false negative',
  );
});

test('Python shutdown timing does not widen a genuinely late stale transition', () => {
  const transition = staleTransitionEvidence({
    shutdownBoundary: pythonShutdownBoundary(),
    observedStaleAt: '2026-07-01T10:00:27Z',
  });

  assert.equal(transition.transition_elapsed_seconds, 13);
  assert.equal(transition.bounded_max_seconds, 12);
  assert.equal(transition.within_bounded_window, false);
});

test('shutdown evidence requires worker detail captured after a confirmed stop', () => {
  assert.throws(
    () => workerShutdownBoundary({
      stopRequestedAt: '2026-07-01T10:00:00Z',
      stoppedAt: '2026-07-01T10:00:15Z',
      workerDetailObservedAt: '2026-07-01T10:00:14Z',
      workerDetail: {
        last_heartbeat_at: '2026-07-01T10:00:13Z',
        stale_after_seconds: 7,
      },
      stopConfirmation: { container_state: { running: false } },
    }),
    /worker detail must be read after the worker stop is confirmed/,
  );
});
