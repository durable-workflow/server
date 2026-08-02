class ControlPlaneTransportError extends Error {
  constructor(method, url, cause, observedAt = new Date().toISOString(), requestDetails = {}) {
    const transport = transportErrorDetails(cause);
    super(`${method} ${url ?? '[unknown URL]'} transport failed: ${transport.message}`, { cause });
    this.name = 'ControlPlaneTransportError';
    this.request = {
      observed_at: observedAt,
      method,
      url: url === null || url === undefined ? null : String(url),
      ...requestDetails,
    };
    this.transport = transport;
  }
}

class ControlPlaneHttpError extends Error {
  constructor(method, url, status, body, observedAt = new Date().toISOString()) {
    super(`${method} ${url ?? '[unknown URL]'} returned HTTP ${status}`);
    this.name = 'ControlPlaneHttpError';
    this.request = {
      observed_at: observedAt,
      method,
      url: url === null || url === undefined ? null : String(url),
    };
    this.response = {
      status,
      body,
    };
  }
}

class FinalVisibilityInvariantError extends Error {
  constructor(failedInvariants, observedVisibility) {
    super(`final operator visibility invariants failed: ${failedInvariants.join(', ')}`);
    this.name = 'FinalVisibilityInvariantError';
    this.failedInvariants = [...failedInvariants];
    this.observedVisibility = observedVisibility;
  }
}

class PersistentFinalVisibilityTransportError extends Error {
  constructor(attempts, cause) {
    super(`final operator visibility transport remained unavailable after ${attempts.length} attempts`, { cause });
    this.name = 'PersistentFinalVisibilityTransportError';
    this.attempts = attempts;
    this.failedRequest = cause?.request ?? null;
    this.transport = cause?.transport ?? transportErrorDetails(cause);
  }
}

function transportErrorDetails(error, depth = 0) {
  const value = error && typeof error === 'object' ? error : null;
  const details = {
    name: String(value?.name ?? 'Error'),
    message: String(value?.message ?? error ?? 'unknown transport error'),
  };

  for (const field of ['code', 'errno', 'syscall', 'address', 'port']) {
    const candidate = value?.[field];
    if (['string', 'number', 'boolean'].includes(typeof candidate)) details[field] = candidate;
  }

  if (depth < 3 && value?.cause && value.cause !== error) {
    details.cause = transportErrorDetails(value.cause, depth + 1);
  }
  if (depth < 3 && Array.isArray(value?.errors)) {
    details.errors = value.errors.slice(0, 4).map((candidate) => transportErrorDetails(candidate, depth + 1));
  }

  return details;
}

function failedConnectionCause(error) {
  let current = error?.transport ?? transportErrorDetails(error);
  while (current?.cause || current?.errors?.[0]) current = current.cause ?? current.errors[0];
  return current ?? null;
}

function inferredConnectionCode(message, exitCode) {
  const explicit = String(message).match(/\b(E(?:AI_AGAIN|CONNREFUSED|CONNRESET|HOSTUNREACH|NETUNREACH|NOTFOUND|PIPE|TIMEDOUT))\b/i);
  if (explicit) return explicit[1].toUpperCase();
  if (/connection (?:was )?refused/i.test(message)) return 'ECONNREFUSED';
  if (/timed?\s*out|timeout/i.test(message) || exitCode === 7) return 'ETIMEDOUT';
  if (/could not resolve|name or service not known|temporary failure in name resolution/i.test(message)) return 'ENOTFOUND';
  return null;
}

function failedCliRequestUrl(message) {
  const candidates = String(message).match(/https?:\/\/[^\s"'<>\\]+/gi) ?? [];
  for (const candidate of candidates.reverse()) {
    const trimmed = candidate.replace(/[\])},.;!?]+$/, '');
    try {
      return new URL(trimmed).toString();
    } catch {
      // Ignore non-URL text that happened to follow an HTTP-looking prefix.
    }
  }
  return null;
}

function cliControlPlaneTransportError({ sample, method, url, observedAt = new Date().toISOString() }) {
  const exitCode = Number(sample?.exit_code);
  if (![3, 7].includes(exitCode)) return null;

  const envelopeError = sample?.output && typeof sample.output === 'object'
    ? sample.output.error
    : null;
  const message = String(envelopeError ?? sample?.stderr ?? sample?.stdout ?? '').trim()
    || `dw exited with transport code ${exitCode}`;
  const connectionCause = new Error(message);
  connectionCause.name = exitCode === 7 ? 'CliTimeoutCause' : 'CliNetworkCause';
  const connectionCode = inferredConnectionCode(message, exitCode);
  if (connectionCode) connectionCause.code = connectionCode;

  const invocation = new Error(`dw transport exit ${exitCode}`, { cause: connectionCause });
  invocation.name = 'CliTransportExit';
  invocation.code = exitCode === 7 ? 'DW_CLI_TIMEOUT' : 'DW_CLI_NETWORK';
  invocation.exit_code = exitCode;

  const intendedMethod = String(method).toUpperCase();
  const intendedUrl = String(url);
  const actualUrl = failedCliRequestUrl(message);

  return new ControlPlaneTransportError('GET', actualUrl, invocation, observedAt, {
    channel: 'cli',
    actual_request_source: actualUrl ? 'cli_error_envelope' : 'cli_error_envelope_missing_url',
    intended_command: Array.isArray(sample?.command) ? [...sample.command] : null,
    intended_request: {
      method: intendedMethod,
      url: intendedUrl,
    },
    cli_exit_code: exitCode,
    cli_error: message,
    cli_error_envelope: sample?.output && typeof sample.output === 'object'
      ? { ...sample.output }
      : null,
  });
}

async function recoverFinalVisibility({
  capture,
  validate,
  maxAttempts = 3,
  retryDelayMs = 1_000,
  wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  observedAt = () => new Date().toISOString(),
}) {
  if (!Number.isInteger(maxAttempts) || maxAttempts < 1) {
    throw new Error('maxAttempts must be a positive integer');
  }

  const attempts = [];
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const startedAt = observedAt();
    try {
      const visibility = await capture();
      const failedInvariants = validate(visibility);
      if (!Array.isArray(failedInvariants)) {
        throw new Error('final visibility validator must return an array of failed invariant names');
      }
      if (failedInvariants.length > 0) {
        throw new FinalVisibilityInvariantError(failedInvariants, visibility);
      }
      attempts.push({
        attempt,
        started_at: startedAt,
        finished_at: observedAt(),
        outcome: 'success',
      });
      return {
        visibility,
        recovery: {
          outcome: attempt === 1 ? 'not_needed' : 'recovered',
          max_attempts: maxAttempts,
          attempts,
          invariants_observed: true,
        },
      };
    } catch (error) {
      if (!(error instanceof ControlPlaneTransportError)) throw error;
      attempts.push({
        attempt,
        started_at: startedAt,
        finished_at: observedAt(),
        outcome: 'transport_error',
        failed_request: error.request,
        transport_error: error.transport,
      });
      if (attempt === maxAttempts) {
        throw new PersistentFinalVisibilityTransportError(attempts, error);
      }
      await wait(retryDelayMs);
    }
  }

  throw new Error('unreachable final visibility recovery state');
}

function classifyPersistentTransportOwner(serverDiagnostics) {
  const container = serverDiagnostics?.container ?? {};
  const state = container.state ?? {};
  const readinessProbe = serverDiagnostics?.readiness_probe ?? {};
  const healthStatus = String(state.health?.status ?? '').toLowerCase();
  const status = String(state.status ?? '').toLowerCase();
  const exitCode = Number(state.exit_code ?? 0);
  const readinessStatus = Number(readinessProbe.status);
  const readinessProvesUnavailable = readinessProbe.ok === false
    && Number.isInteger(readinessStatus)
    && readinessStatus >= 500
    && readinessStatus < 600;
  const containerProvesUnavailable = container.present === true && (
    state.running === false
    || state.restarting === true
    || ['dead', 'exited', 'removing', 'restarting'].includes(status)
    || healthStatus === 'unhealthy'
    || (state.running !== true && Number.isFinite(exitCode) && exitCode !== 0)
  );
  const provenUnavailable = containerProvesUnavailable || readinessProvesUnavailable;

  if (provenUnavailable) {
    return {
      classification: 'standalone-server-availability-gap',
      finding_type: 'standalone_server_availability_gap',
      owning_surface: 'server',
      runner_blocked: false,
      reason: 'Retained container or readiness state proves that the standalone server was unavailable.',
    };
  }

  return {
    classification: 'runner-transport-gap',
    finding_type: 'conformance_runner_transport_gap',
    owning_surface: 'conformance_harness',
    runner_blocked: true,
    reason: 'Retained diagnostics do not prove a standalone server availability failure.',
  };
}

function persistentTransportEvidence({ error, serverDiagnostics, completedBehavior }) {
  const ownership = classifyPersistentTransportOwner(serverDiagnostics);
  return {
    ...ownership,
    completed_behavior_before_final_visibility: completedBehavior,
    final_visibility_transport: {
      outcome: 'persistent_outage',
      attempts: error?.attempts ?? [],
      failed_request: error?.failedRequest ?? null,
      transport_error: error?.transport ?? transportErrorDetails(error),
      underlying_connection_cause: failedConnectionCause(error),
      invariants_observed: false,
    },
    server_diagnostics: serverDiagnostics,
  };
}

export {
  cliControlPlaneTransportError,
  ControlPlaneHttpError,
  ControlPlaneTransportError,
  FinalVisibilityInvariantError,
  PersistentFinalVisibilityTransportError,
  classifyPersistentTransportOwner,
  failedConnectionCause,
  persistentTransportEvidence,
  recoverFinalVisibility,
  transportErrorDetails,
};
