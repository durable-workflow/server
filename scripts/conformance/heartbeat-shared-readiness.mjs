import { performance } from 'node:perf_hooks';
import { setTimeout as delay } from 'node:timers/promises';

// Cap host readiness at one quarter of the six-minute wave to protect the remaining work.
const DEFAULT_TIMEOUT_MS = 90_000;
const DEFAULT_ATTEMPT_TIMEOUT_MS = 2_000;
const DEFAULT_RETRY_INTERVAL_MS = 250;
const CONTAINER_PORT = '8080/tcp';

function describeError(error) {
  const details = [];
  const seen = new Set();
  let current = error;

  while (current && !seen.has(current) && details.length < 4) {
    seen.add(current);
    const name = String(current.name ?? 'Error').trim() || 'Error';
    const code = String(current.code ?? '').trim();
    const message = String(current.message ?? current).trim();
    details.push(`${name}${code ? ` [${code}]` : ''}: ${message}`);
    current = current.cause;
  }

  return details.join(' <- ') || 'unknown readiness error';
}

function elapsedMilliseconds(monotonicNow, startedAt) {
  return Math.max(0, Math.round(monotonicNow() - startedAt));
}

export function parsePublishedPortBindings(output) {
  const bindings = [];

  for (const rawLine of String(output ?? '').split(/\r?\n/)) {
    const line = rawLine.trim().split(/\s+/, 1)[0] ?? '';
    if (!line) continue;

    let host = '';
    let portText = '';
    if (line.startsWith('[') && line.includes(']:')) {
      [host, portText] = line.slice(1).split(']:', 2);
    } else {
      const separator = line.lastIndexOf(':');
      if (separator < 1) continue;
      host = line.slice(0, separator);
      portText = line.slice(separator + 1);
    }

    const port = Number.parseInt(portText, 10);
    if (!Number.isInteger(port) || port < 1 || port > 65_535) continue;
    bindings.push({ host, port });
  }

  return bindings;
}

export function selectLoopbackPublishedEndpoint(output, expectedPort) {
  const bindings = parsePublishedPortBindings(output);
  const ports = [...new Set(bindings.map((binding) => binding.port))];
  if (ports.length === 0) {
    throw new Error('Compose did not report a published server port');
  }
  if (ports.length !== 1) {
    throw new Error(`Compose reported conflicting published server ports: ${ports.join(', ')}`);
  }
  if (Number.isInteger(expectedPort) && ports[0] !== expectedPort) {
    throw new Error(
      `Compose published server port ${ports[0]} instead of requested port ${expectedPort}`,
    );
  }

  return {
    host_url: `http://127.0.0.1:${ports[0]}`,
    port: ports[0],
    bindings,
  };
}

function containerPortBindings(container) {
  const bindings = container?.NetworkSettings?.Ports?.[CONTAINER_PORT];
  return Array.isArray(bindings) ? bindings : [];
}

export function classifySharedServerStartup({
  container,
  composePort,
  expectedPort,
  readiness = null,
  readinessTransport = 'published_host',
}) {
  const state = container?.State;
  if (!state || state.Status !== 'running' || state.Running !== true) {
    return {
      kind: 'container_failure',
      reason: `server container state is ${state?.Status ?? 'unavailable'}`,
    };
  }
  if (state.Health?.Status !== 'healthy') {
    return {
      kind: 'container_failure',
      reason: `server container health is ${state.Health?.Status ?? 'unavailable'}`,
    };
  }

  const publishedBindings = parsePublishedPortBindings(composePort?.stdout);
  const publishedPorts = [...new Set(publishedBindings.map((binding) => binding.port))];
  const inspectedPorts = containerPortBindings(container)
    .map((binding) => Number.parseInt(String(binding?.HostPort ?? ''), 10))
    .filter((port) => Number.isInteger(port));
  if (composePort?.status !== 0) {
    return {
      kind: 'published_port_failure',
      reason: `docker compose port exited with status ${composePort?.status ?? 'unavailable'}`,
    };
  }
  if (publishedPorts.length !== 1
    || (Number.isInteger(expectedPort) && publishedPorts[0] !== expectedPort)) {
    return {
      kind: 'published_port_failure',
      reason: publishedPorts.length === 0
        ? 'Compose reported no published server port'
        : `Compose reported unexpected server ports: ${publishedPorts.join(', ')}`,
    };
  }
  if (!inspectedPorts.includes(publishedPorts[0])) {
    return {
      kind: 'published_port_failure',
      reason: `container inspection did not retain host port ${publishedPorts[0]}`,
    };
  }

  if (!readiness) return null;
  if (readinessTransport === 'executor_compatibility_relay'
    && [502, 504].includes(readiness.final_status)) {
    return {
      kind: readiness.final_status === 504 ? 'relay_target_timeout' : 'relay_target_failure',
      reason: readiness.final_status === 504
        ? 'the executor-network relay did not complete the authenticated readiness request'
        : 'the executor-network relay could not reach the server service endpoint',
    };
  }
  if (Number.isInteger(readiness.final_status)) {
    return {
      kind: 'readiness_response_failure',
      reason: `host endpoint last returned HTTP ${readiness.final_status}`,
    };
  }

  const finalError = String(readiness.final_error ?? '');
  if (/ECONNREFUSED/i.test(finalError)) {
    if (readinessTransport === 'executor_compatibility_relay') {
      return {
        kind: 'relay_bind_timeout',
        reason: 'the workspace relay did not bind before its bounded readiness deadline',
      };
    }
    if (readinessTransport === 'executor_network_attachment') {
      return {
        kind: 'executor_network_failure',
        reason: 'the attached executor could not reach the server service endpoint',
      };
    }
    return {
      kind: 'host_bind_timeout',
      reason: 'container health and published-port metadata passed but the host endpoint never bound',
    };
  }
  if (/ECONNRESET/i.test(finalError)) {
    if (readinessTransport === 'executor_network_attachment') {
      return {
        kind: 'executor_network_reset',
        reason: 'the server service reset the executor-network readiness connection',
      };
    }
    return {
      kind: 'host_connection_reset',
      reason: 'the published host endpoint accepted and then reset the readiness connection',
    };
  }
  if (/ETIMEDOUT|AbortError/i.test(finalError)) {
    if (readinessTransport === 'executor_network_attachment') {
      return {
        kind: 'executor_network_timeout',
        reason: 'the server service did not complete the executor-network readiness response',
      };
    }
    return {
      kind: 'host_endpoint_timeout',
      reason: 'the published host endpoint did not complete a readiness response',
    };
  }
  if (readinessTransport === 'executor_network_attachment') {
    return {
      kind: 'executor_network_failure',
      reason: finalError || 'the attached executor could not reach the server service endpoint',
    };
  }

  return {
    kind: 'host_reachability_failure',
    reason: finalError || 'the host endpoint did not return a response',
  };
}

export class HeartbeatReadinessTimeoutError extends Error {
  constructor(readiness) {
    const finalStatus = readiness.final_status ?? 'none';
    const finalError = readiness.final_error ?? 'none';
    super(
      `shared published server readiness timed out after ${readiness.timeout_ms}ms `
      + `(${readiness.attempts} attempts); final status=${finalStatus}; `
      + `final error=${finalError}`,
    );
    this.name = 'HeartbeatReadinessTimeoutError';
    this.readiness = readiness;
  }
}

export async function waitForAuthenticatedReadiness({
  url,
  token,
  timeoutMs = DEFAULT_TIMEOUT_MS,
  attemptTimeoutMs = DEFAULT_ATTEMPT_TIMEOUT_MS,
  retryIntervalMs = DEFAULT_RETRY_INTERVAL_MS,
  fetchImpl = globalThis.fetch,
  monotonicNow = () => performance.now(),
  sleep = (milliseconds) => delay(milliseconds),
}) {
  for (const [name, value] of Object.entries({
    timeoutMs,
    attemptTimeoutMs,
    retryIntervalMs,
  })) {
    if (!Number.isFinite(value) || value <= 0) {
      throw new Error(`${name} must be a positive finite number`);
    }
  }
  if (typeof fetchImpl !== 'function') throw new Error('fetchImpl must be a function');

  const startedAt = monotonicNow();
  const deadline = startedAt + timeoutMs;
  let attempts = 0;
  let finalStatus = null;
  let finalError = null;

  while (true) {
    const remainingMs = deadline - monotonicNow();
    if (remainingMs <= 0) break;
    attempts += 1;

    try {
      const response = await fetchImpl(url, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        signal: AbortSignal.timeout(
          Math.max(1, Math.ceil(Math.min(attemptTimeoutMs, remainingMs))),
        ),
      });
      finalStatus = Number.isInteger(response?.status) ? response.status : null;
      finalError = response?.ok
        ? null
        : `HTTP ${finalStatus ?? 'unknown'}${response?.statusText ? ` ${response.statusText}` : ''}`;

      if (response?.ok && monotonicNow() <= deadline) {
        return {
          status: finalStatus,
          attempts,
          elapsed_ms: elapsedMilliseconds(monotonicNow, startedAt),
          timeout_ms: timeoutMs,
          final_status: finalStatus,
          final_error: null,
        };
      }
      if (response?.ok) {
        finalError = 'successful response arrived after the readiness deadline';
      }
    } catch (error) {
      finalStatus = null;
      finalError = describeError(error);
    }

    const retryBudgetMs = deadline - monotonicNow();
    if (retryBudgetMs <= 0) break;
    await sleep(Math.min(retryIntervalMs, retryBudgetMs));
  }

  throw new HeartbeatReadinessTimeoutError({
    timeout_ms: timeoutMs,
    attempts,
    elapsed_ms: elapsedMilliseconds(monotonicNow, startedAt),
    final_status: finalStatus,
    final_error: finalError,
  });
}
