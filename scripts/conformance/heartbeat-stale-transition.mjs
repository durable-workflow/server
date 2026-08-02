function timestampMillis(field, value) {
  const milliseconds = Date.parse(value);
  if (typeof value !== 'string' || !Number.isFinite(milliseconds)) {
    throw new Error(`${field} must be a valid timestamp`);
  }
  return milliseconds;
}

function positiveSeconds(field, value) {
  const seconds = Number(value);
  if (!Number.isFinite(seconds) || seconds <= 0) {
    throw new Error(`${field} must be a positive number`);
  }
  return seconds;
}

function isoTimestamp(milliseconds) {
  return new Date(milliseconds).toISOString();
}

export function workerShutdownBoundary({
  stopRequestedAt,
  stoppedAt,
  workerDetailObservedAt,
  workerDetail,
  finalHeartbeatRecord = null,
  stopConfirmation,
}) {
  const requestedMilliseconds = timestampMillis('stopRequestedAt', stopRequestedAt);
  const stoppedMilliseconds = timestampMillis('stoppedAt', stoppedAt);
  const detailObservedMilliseconds = timestampMillis('workerDetailObservedAt', workerDetailObservedAt);
  const finalHeartbeatMilliseconds = timestampMillis(
    'workerDetail.last_heartbeat_at',
    workerDetail?.last_heartbeat_at,
  );
  if (stoppedMilliseconds < requestedMilliseconds) {
    throw new Error('stoppedAt cannot precede stopRequestedAt');
  }
  if (detailObservedMilliseconds < stoppedMilliseconds) {
    throw new Error('worker detail must be read after the worker stop is confirmed');
  }
  if (finalHeartbeatMilliseconds > detailObservedMilliseconds) {
    throw new Error('final accepted heartbeat cannot postdate its worker-detail observation');
  }
  if (stopConfirmation?.container_state?.running !== false) {
    throw new Error('worker container stop was not confirmed');
  }

  const finalAcknowledgement = finalHeartbeatRecord?.acknowledgement ?? null;
  const acknowledgementStaleAfter = finalAcknowledgement?.stale_after_seconds;
  const detailStaleAfter = workerDetail?.stale_after_seconds;
  const advertisedStaleAfterSeconds = positiveSeconds(
    'advertised stale-after interval',
    detailStaleAfter ?? acknowledgementStaleAfter,
  );
  if (acknowledgementStaleAfter !== undefined
    && detailStaleAfter !== undefined
    && Number(acknowledgementStaleAfter) !== Number(detailStaleAfter)) {
    throw new Error('final heartbeat acknowledgement and worker detail advertise different stale-after intervals');
  }

  return {
    stop_requested_at: stopRequestedAt,
    stopped_at: stoppedAt,
    stop_duration_seconds: (stoppedMilliseconds - requestedMilliseconds) / 1_000,
    worker_detail_observed_at: workerDetailObservedAt,
    final_accepted_heartbeat_at: workerDetail.last_heartbeat_at,
    last_sdk_heartbeat_acknowledgement_observed_at: finalHeartbeatRecord?.observed_at
      ?? finalHeartbeatRecord?.acknowledgement_logged_at
      ?? null,
    last_sdk_heartbeat_acknowledgement: finalAcknowledgement,
    advertised_stale_after_seconds: advertisedStaleAfterSeconds,
    stop_confirmation: stopConfirmation,
  };
}

export function refineWorkerShutdownBoundary({
  shutdownBoundary,
  workerDetailObservedAt,
  workerDetail,
}) {
  const lastSdkHeartbeatRecord = shutdownBoundary?.last_sdk_heartbeat_acknowledgement
    ? {
      observed_at: shutdownBoundary.last_sdk_heartbeat_acknowledgement_observed_at,
      acknowledgement: shutdownBoundary.last_sdk_heartbeat_acknowledgement,
    }
    : null;
  return workerShutdownBoundary({
    stopRequestedAt: shutdownBoundary?.stop_requested_at,
    stoppedAt: shutdownBoundary?.stopped_at,
    workerDetailObservedAt,
    workerDetail,
    finalHeartbeatRecord: lastSdkHeartbeatRecord,
    stopConfirmation: shutdownBoundary?.stop_confirmation,
  });
}

export function staleTransitionEvidence({
  shutdownBoundary,
  observedStaleAt,
  probeGraceSeconds = 5,
}) {
  const finalHeartbeatMilliseconds = timestampMillis(
    'shutdownBoundary.final_accepted_heartbeat_at',
    shutdownBoundary?.final_accepted_heartbeat_at,
  );
  const stoppedMilliseconds = timestampMillis(
    'shutdownBoundary.stopped_at',
    shutdownBoundary?.stopped_at,
  );
  const observedStaleMilliseconds = timestampMillis('observedStaleAt', observedStaleAt);
  const staleAfterSeconds = positiveSeconds(
    'shutdownBoundary.advertised_stale_after_seconds',
    shutdownBoundary?.advertised_stale_after_seconds,
  );
  const graceSeconds = Number(probeGraceSeconds);
  if (!Number.isFinite(graceSeconds) || graceSeconds < 0) {
    throw new Error('probeGraceSeconds must be a non-negative number');
  }

  const transitionElapsedSeconds = (
    observedStaleMilliseconds - finalHeartbeatMilliseconds
  ) / 1_000;
  const boundedMaxSeconds = staleAfterSeconds + graceSeconds;

  return {
    ...shutdownBoundary,
    causal_stale_anchor: 'final_server_accepted_heartbeat',
    stale_deadline_at: isoTimestamp(finalHeartbeatMilliseconds + staleAfterSeconds * 1_000),
    observed_stale_at: observedStaleAt,
    stale_after_seconds: staleAfterSeconds,
    transition_elapsed_seconds: transitionElapsedSeconds,
    confirmed_stop_to_stale_seconds: (observedStaleMilliseconds - stoppedMilliseconds) / 1_000,
    probe_grace_seconds: graceSeconds,
    bounded_max_seconds: boundedMaxSeconds,
    bounded_observation_deadline_at: isoTimestamp(
      finalHeartbeatMilliseconds + boundedMaxSeconds * 1_000,
    ),
    within_bounded_window: transitionElapsedSeconds >= 0
      && transitionElapsedSeconds <= boundedMaxSeconds,
  };
}
