import { performance } from 'node:perf_hooks';
import { setTimeout as delay } from 'node:timers/promises';

const DEFAULT_TIMEOUT_MS = 15_000;
const DEFAULT_ATTEMPT_TIMEOUT_MS = 2_000;
const DEFAULT_RETRY_INTERVAL_MS = 250;

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
