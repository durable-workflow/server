import {
  ControlPlaneHttpError,
  ControlPlaneTransportError,
  classifyPersistentTransportOwner,
  failedConnectionCause,
  transportErrorDetails,
} from './heartbeat-final-visibility.mjs';

class PersistentPostStopDetailTransportError extends Error {
  constructor(attempts, cause, maxAttempts, retryDelayMs) {
    super(`post-stop worker detail transport remained unavailable after ${attempts.length} attempts`, {
      cause,
    });
    this.name = 'PersistentPostStopDetailTransportError';
    this.attempts = attempts;
    this.failedRequest = cause?.request ?? null;
    this.transport = cause?.transport ?? transportErrorDetails(cause);
    this.maxAttempts = maxAttempts;
    this.retryDelayMs = retryDelayMs;
  }
}

class PostStopDetailHttpError extends Error {
  constructor(attempts, cause, maxAttempts, retryDelayMs) {
    super(`post-stop worker detail returned a semantic HTTP failure: ${cause.message}`, { cause });
    this.name = 'PostStopDetailHttpError';
    this.attempts = attempts;
    this.failedRequest = cause.request;
    this.response = cause.response;
    this.maxAttempts = maxAttempts;
    this.retryDelayMs = retryDelayMs;
  }
}

function recoveryEvidence(error, outcome) {
  return {
    outcome,
    focused_read_only: true,
    shared_wave_retried: false,
    max_attempts: error?.maxAttempts ?? null,
    retry_delay_ms: error?.retryDelayMs ?? null,
    attempts: error?.attempts ?? [],
    failed_request: error?.failedRequest ?? null,
    response: error?.response ?? null,
  };
}

async function recoverPostStopWorkerDetail({
  capture,
  maxAttempts = 3,
  retryDelayMs = 1_000,
  wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  observedAt = () => new Date().toISOString(),
}) {
  if (!Number.isInteger(maxAttempts) || maxAttempts < 1) {
    throw new Error('maxAttempts must be a positive integer');
  }
  if (!Number.isInteger(retryDelayMs) || retryDelayMs < 0) {
    throw new Error('retryDelayMs must be a non-negative integer');
  }

  const attempts = [];
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const startedAt = observedAt();
    try {
      const workerDetail = await capture();
      const finishedAt = observedAt();
      attempts.push({
        attempt,
        started_at: startedAt,
        finished_at: finishedAt,
        outcome: 'success',
      });
      return {
        workerDetail,
        workerDetailObservedAt: finishedAt,
        recovery: {
          outcome: attempt === 1 ? 'not_needed' : 'recovered',
          focused_read_only: true,
          shared_wave_retried: false,
          max_attempts: maxAttempts,
          retry_delay_ms: retryDelayMs,
          attempts,
        },
      };
    } catch (error) {
      const finishedAt = observedAt();
      if (error instanceof ControlPlaneTransportError) {
        attempts.push({
          attempt,
          started_at: startedAt,
          finished_at: finishedAt,
          outcome: 'transport_error',
          failed_request: error.request,
          transport_error: error.transport,
        });
        if (attempt === maxAttempts) {
          throw new PersistentPostStopDetailTransportError(
            attempts,
            error,
            maxAttempts,
            retryDelayMs,
          );
        }
        await wait(retryDelayMs);
        continue;
      }
      if (error instanceof ControlPlaneHttpError) {
        attempts.push({
          attempt,
          started_at: startedAt,
          finished_at: finishedAt,
          outcome: 'http_error',
          failed_request: error.request,
          response: error.response,
        });
        throw new PostStopDetailHttpError(attempts, error, maxAttempts, retryDelayMs);
      }
      throw error;
    }
  }

  throw new Error('unreachable post-stop worker detail recovery state');
}

function persistentPostStopDetailEvidence({ error, serverDiagnostics, shutdown }) {
  const ownership = classifyPersistentTransportOwner(serverDiagnostics);
  return {
    ...ownership,
    shutdown,
    post_stop_worker_detail_transport: {
      ...recoveryEvidence(error, 'persistent_outage'),
      transport_error: error?.transport ?? transportErrorDetails(error),
      underlying_connection_cause: failedConnectionCause(error),
      worker_detail_observed: false,
    },
    server_diagnostics: serverDiagnostics,
  };
}

function semanticPostStopDetailEvidence({ error, serverDiagnostics, shutdown }) {
  return {
    classification: 'standalone-server-worker-detail-http-gap',
    finding_type: 'standalone_server_worker_detail_http_gap',
    owning_surface: 'server',
    runner_blocked: false,
    reason: 'The post-stop worker-detail endpoint returned an HTTP response that violated the expected read contract.',
    shutdown,
    post_stop_worker_detail_transport: {
      ...recoveryEvidence(error, 'semantic_http_failure'),
      worker_detail_observed: false,
    },
    server_diagnostics: serverDiagnostics,
  };
}

export {
  PersistentPostStopDetailTransportError,
  PostStopDetailHttpError,
  persistentPostStopDetailEvidence,
  recoverPostStopWorkerDetail,
  semanticPostStopDetailEvidence,
};
